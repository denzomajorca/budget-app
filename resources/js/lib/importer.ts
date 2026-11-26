/**
 * Reads bank files in the browser and turns rows into plain values.
 * CSV is parsed here; XLS/XLSX use SheetJS, loaded only when needed.
 */
export type Cell = string | number | Date | null;
export type Table = Cell[][];
export type DateFormat = 'MDY' | 'DMY' | 'YMD';
export type SignMode = 'neg-out' | 'pos-out';

export interface Mapping {
    date: number;
    payee: number;
    amount: number;
    debit: number;
    credit: number;
    category: number;
    memo: number;
}
export interface Settings {
    mapping: Mapping;
    headers: string[];
    dateFormat: DateFormat;
    sign: SignMode;
}
export interface ParsedRow {
    line: number;
    date: string | null;
    amount: number | null;
    payee: string;
    category: string;
    memo: string;
    error: string | null;
}

export function parseCSV(text: string): Table {
    if (text.charCodeAt(0) === 0xfeff) text = text.slice(1);
    const sep = guessSeparator(text);
    const out: Table = [];
    let row: string[] = [];
    let f = '';
    let q = false;
    for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (q) {
            if (c === '"' && text[i + 1] === '"') {
                f += '"';
                i++;
            } else if (c === '"') q = false;
            else f += c;
        } else if (c === '"') q = true;
        else if (c === sep) {
            row.push(f);
            f = '';
        } else if (c === '\n' || c === '\r') {
            if (c === '\r' && text[i + 1] === '\n') i++;
            row.push(f);
            out.push(row);
            row = [];
            f = '';
        } else f += c;
    }
    if (f || row.length) {
        row.push(f);
        out.push(row);
    }
    return out.filter((r) => r.some((x) => String(x ?? '').trim() !== ''));
}

function guessSeparator(text: string): string {
    const first = text.split(/\r?\n/, 1)[0] ?? '';
    const counts = [',', ';', '\t'].map((s) => [s, first.split(s).length] as const);
    counts.sort((a, b) => b[1] - a[1]);
    return counts[0][1] > 1 ? counts[0][0] : ',';
}

export async function readFile(file: File): Promise<Table> {
    if (/\.(xlsx|xls)$/i.test(file.name)) {
        const XLSX = await import('xlsx');
        const wb = XLSX.read(await file.arrayBuffer(), { type: 'array', cellDates: true });
        const sheet = wb.Sheets[wb.SheetNames[0]];
        const rows = XLSX.utils.sheet_to_json<Cell[]>(sheet, { header: 1, raw: true, defval: null });
        return rows.filter((r) => r.some((x) => x !== null && String(x).trim() !== ''));
    }
    return parseCSV(await file.text());
}

/** Bank files sometimes start with a few lines of account info before the headers. */
export function headerRowIndex(t: Table): number {
    for (let i = 0; i < Math.min(t.length, 15); i++) {
        const cells = t[i].map((c) =>
            String(c ?? '')
                .trim()
                .toLowerCase(),
        );
        if (cells.some((c) => /\bdate\b/.test(c)) && cells.filter(Boolean).length >= 2) return i;
    }
    return 0;
}

export function guessMapping(headers: string[]): Mapping {
    const h = headers.map((x) => x.trim().toLowerCase());
    const find = (re: RegExp, not?: RegExp) => h.findIndex((x) => re.test(x) && !(not && not.test(x)));
    const m: Mapping = {
        date: [find(/^(transaction )?date$/), find(/date/, /post/), find(/date/)].find((i) => i >= 0) ?? -1,
        payee: find(/payee|description|merchant|name/),
        amount: find(/amount/),
        debit: find(/debit|withdrawal|money out/),
        credit: find(/credit|deposit|money in/),
        category: find(/categor/),
        memo: find(/memo|note/),
    };
    if (m.amount >= 0) {
        m.debit = -1;
        m.credit = -1;
    }
    return m;
}

export function guessDateFormat(values: Cell[]): DateFormat {
    let dmy = false;
    for (const v of values) {
        if (v instanceof Date || typeof v === 'number') continue;
        const s = String(v ?? '').trim();
        if (/^\d{4}[-/.]\d{1,2}[-/.]\d{1,2}/.test(s)) return 'YMD';
        const m = s.match(/^(\d{1,2})[-/.](\d{1,2})[-/.]\d{2,4}/);
        if (m && +m[1] > 12) dmy = true;
    }
    return dmy ? 'DMY' : 'MDY';
}

const pad = (n: number) => String(n).padStart(2, '0');
const iso = (y: number, m: number, d: number) => {
    const dt = new Date(y, m - 1, d);
    return dt.getFullYear() === y && dt.getMonth() === m - 1 && dt.getDate() === d ? `${y}-${pad(m)}-${pad(d)}` : null;
};

export function parseDate(v: Cell, fmt: DateFormat): string | null {
    if (v instanceof Date) return isNaN(v.getTime()) ? null : iso(v.getFullYear(), v.getMonth() + 1, v.getDate());
    if (typeof v === 'number') {
        // Excel serial day number
        const d = new Date(Math.round((v - 25569) * 864e5));
        return iso(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate());
    }
    const s = String(v ?? '').trim();
    let m = s.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})/);
    if (m) return iso(+m[1], +m[2], +m[3]);
    m = s.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2}|\d{4})\b/);
    if (!m) return null;
    const y = m[3].length === 2 ? 2000 + +m[3] : +m[3];
    return fmt === 'DMY' ? iso(y, +m[2], +m[1]) : iso(y, +m[1], +m[2]);
}

/** "$1,234.56", "-12.30", "(12.30)", "12.30-" -> cents. */
export function parseAmount(v: Cell): number | null {
    if (typeof v === 'number') return Math.round(v * 100);
    let s = String(v ?? '').trim();
    if (!s) return null;
    let neg = false;
    if (/^\(.*\)$/.test(s)) {
        neg = true;
        s = s.slice(1, -1);
    }
    if (/-\s*$/.test(s)) {
        neg = true;
        s = s.replace(/-\s*$/, '');
    }
    if (s.includes('-')) neg = !neg;
    s = s.replace(/[$€£\s,+-]/g, '');
    if (!/^\d*\.?\d+$/.test(s)) return null;
    const cents = Math.round(parseFloat(s) * 100);
    return neg ? -cents : cents;
}

const text = (v: Cell) => (v instanceof Date ? v.toISOString().slice(0, 10) : String(v ?? '').trim());

export function toRows(body: Table, firstLine: number, s: Settings): ParsedRow[] {
    const m = s.mapping;
    return body.map((r, i) => {
        const date = m.date >= 0 ? parseDate(r[m.date], s.dateFormat) : null;
        let amount: number | null = null;
        if (m.amount >= 0) {
            amount = parseAmount(r[m.amount]);
            if (amount !== null && s.sign === 'pos-out') amount = -amount;
        } else if (m.debit >= 0 || m.credit >= 0) {
            const d = m.debit >= 0 ? parseAmount(r[m.debit]) : null;
            const c = m.credit >= 0 ? parseAmount(r[m.credit]) : null;
            if (d !== null || c !== null) amount = (c ? Math.abs(c) : 0) - (d ? Math.abs(d) : 0);
        }
        const payee = m.payee >= 0 ? text(r[m.payee]) : '';
        const error = !date ? 'Date could not be read' : amount === null ? 'Amount could not be read' : !payee ? 'No payee' : null;
        return {
            line: firstLine + i,
            date,
            amount,
            payee,
            category: m.category >= 0 ? text(r[m.category]) : '',
            memo: m.memo >= 0 ? text(r[m.memo]) : '',
            error,
        };
    });
}

/** Display form of "Main:Sub" text: "Main › Sub", "(blank)" dropped. */
export function categoryLabel(raw: string): string {
    const [main, sub] = raw.split(':', 2).map((x) => x?.trim());
    const clean = (x?: string) => (x && x.toLowerCase() !== '(blank)' ? x : '');
    const a = clean(main);
    const b = clean(sub);
    return a && b ? `${a} › ${b}` : a || b || '';
}
