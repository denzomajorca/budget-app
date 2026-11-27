/**
 * Reads investment files in the browser:
 * - activity files (John Hancock 401(k) activity, Fidelity "Accounts History", or any file
 *   with Date, Description and Amount columns)
 * - holdings files (Fidelity "Portfolio Positions", or any file with a name and a value column)
 */
import { guessDateFormat, parseAmount, parseDate, type Cell, type Table } from '@/lib/importer';

export type ActivityKind =
    | 'employee'
    | 'employer'
    | 'loan_repayment'
    | 'deposit'
    | 'withdrawal'
    | 'loan'
    | 'buy'
    | 'sell'
    | 'earnings'
    | 'fee'
    | 'other';

export interface ActivityRow {
    date: string;
    kind: ActivityKind;
    description: string;
    fund: string | null;
    symbol: string | null;
    amount: number; // cents
    units: number | null;
    price: number | null;
    ref: string | null;
}
export interface HoldingRow {
    symbol: string | null;
    name: string;
    quantity: number | null;
    price: number | null;
    value: number; // cents
    cost: number | null; // cents
    account: string;
}
export interface BalanceRow {
    date: string;
    balance: number; // cents
    loan: number | null; // cents
    money_in: number | null; // cents put in since the previous statement
}
export type Parsed =
    | { type: 'balances'; source: string; rows: BalanceRow[]; skipped: number }
    | { type: 'activity'; source: string; rows: ActivityRow[]; skipped: number }
    | { type: 'holdings'; source: string; rows: HoldingRow[]; asOf: string | null; accounts: string[] };

const low = (c: Cell) =>
    String(c ?? '')
        .trim()
        .toLowerCase();
const txt = (c: Cell) => (c instanceof Date ? c.toISOString().slice(0, 10) : String(c ?? '').trim());

/** "1,234.5678", "$12.30", "--" -> number or null. */
export function num(c: Cell): number | null {
    if (typeof c === 'number') return c;
    const s = String(c ?? '')
        .replace(/[$,\s+]/g, '')
        .replace(/^\((.*)\)$/, '-$1');
    if (!/^-?\d*\.?\d+$/.test(s)) return null;
    return parseFloat(s);
}

export function kindOf(text: string, employer401k: boolean): ActivityKind {
    const t = text.toLowerCase();
    if (/loan repay/.test(t)) return 'loan_repayment';
    if (/\bloan\b/.test(t)) return 'loan';
    if (/employer|match|safe harbor|profit shar/.test(t)) return 'employer';
    if (/employee|deferral|elective/.test(t)) return 'employee';
    if (/reinvest/.test(t)) return 'buy';
    if (/you bought|\bbought\b|\bbuy\b|purchase/.test(t)) return 'buy';
    if (/you sold|\bsold\b|\bsell\b|redemption/.test(t)) return 'sell';
    if (/dividend|interest|cap(ital)? gain/.test(t)) return 'earnings';
    if (/\bfees?\b|commission|charge/.test(t)) return 'fee';
    if (/withdraw|distribution|funds transfer paid|transferred to|transfer (to|out)|wire.*(out|sent)|disburse/.test(t)) return 'withdrawal';
    if (/contribution/.test(t)) return employer401k ? 'employee' : 'deposit';
    if (/deposit|funds transfer received|transferred from|transfer (from|in)|rollover|wire.*(in|received)|check received/.test(t)) return 'deposit';
    return 'other';
}

function findHeader(t: Table, test: (cells: string[]) => boolean): number {
    for (let i = 0; i < Math.min(t.length, 20); i++) if (test(t[i].map(low))) return i;
    return -1;
}

export function parseInvestmentFile(t: Table): Parsed {
    // Past statement balances: Date plus a balance, and no Amount column.
    const bal = findHeader(
        t,
        (c) =>
            c.some((x) => /(^|statement |as of )date$|^period end/.test(x)) &&
            c.some((x) => /balance|^value$|total value/.test(x)) &&
            !c.some((x) => /^amount/.test(x)),
    );
    if (bal >= 0) return parseBalances(t, bal);
    const hold = findHeader(
        t,
        (c) => c.some((x) => /current value|market value|^value$|^balance$|ending balance/.test(x)) && !c.some((x) => /date$/.test(x)),
    );
    if (hold >= 0) return parseHoldings(t, hold);
    const act = findHeader(t, (c) => c.some((x) => /(^|run |trade |transaction )date$/.test(x)) && c.some((x) => /amount/.test(x)));
    if (act < 0) throw new Error('It has no Date and Amount columns, and no holdings with a value, so it doesn’t look like an investment file.');
    return parseActivity(t, act);
}

function parseActivity(t: Table, hi: number): Parsed {
    const H = t[hi].map(low);
    const col = (...res: RegExp[]) => {
        for (const re of res) {
            const i = H.findIndex((h) => re.test(h));
            if (i >= 0) return i;
        }
        return -1;
    };
    const c = {
        date: col(/^(run |trade |transaction )?date$/),
        action: col(/^action$/),
        desc: col(/^description$/, /description/, /^name$/),
        amount: col(/^amount/),
        sub: col(/^subcategory$/),
        detail: col(/^detail/),
        symbol: col(/^symbol$/),
        qty: col(/^quantity/, /^shares/, /^units/),
        price: col(/^price/),
    };
    const fidelity = c.action >= 0;
    const jh = c.detail >= 0 && c.sub >= 0;
    const body = t.slice(hi + 1);
    const fmt = guessDateFormat(body.map((r) => r[c.date]));
    let skipped = 0;
    const rows: ActivityRow[] = [];
    for (const r of body) {
        const date = parseDate(r[c.date], fmt);
        const amount = parseAmount(r[c.amount]);
        if (!date || amount === null) {
            skipped++;
            continue;
        }
        const detail = c.detail >= 0 ? txt(r[c.detail]) : '';
        const um = detail.match(/units\s+([\d.]+)\s+at\s+\$?([\d.]+)/i);
        const symbol = c.symbol >= 0 ? txt(r[c.symbol]).replace(/\*+$/, '') || null : null;
        let description = c.desc >= 0 ? txt(r[c.desc]) : '';
        let fund: string | null = null;
        if (fidelity) {
            // Fidelity: Action says what happened, Description names the fund or stock.
            fund = symbol ? description || symbol : null;
            description = txt(r[c.action]);
        } else if (description.includes('·')) {
            fund = description.split('·').pop()!.trim() || null;
        }
        const units = um ? +um[1] : c.qty >= 0 ? num(r[c.qty]) : null;
        const price = um ? +um[2] : c.price >= 0 ? num(r[c.price]) : null;
        if (amount === 0 && !units) {
            skipped++;
            continue;
        }
        const kindText = `${c.sub >= 0 ? txt(r[c.sub]) : ''} ${description}`;
        rows.push({
            date,
            kind: kindOf(kindText, jh),
            description: description.slice(0, 255),
            fund: fund ? fund.slice(0, 150) : null,
            symbol: symbol ? symbol.slice(0, 20) : null,
            amount,
            units: units !== null ? Math.abs(units) : null,
            price: price !== null && price > 0 ? price : null,
            ref: detail.match(/transaction\s+(\d+)/i)?.[1] ?? null,
        });
    }
    if (!rows.length) throw new Error('No activity rows with a date and an amount were found in it.');
    return { type: 'activity', source: fidelity ? 'Fidelity' : jh ? 'John Hancock' : 'file', rows, skipped };
}

const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
const pad = (n: number) => String(n).padStart(2, '0');

/** Fidelity ends its positions file with "Date downloaded Nov-27-2026 ...". */
function downloadedDate(t: Table): string | null {
    for (const r of t.slice(-12)) {
        const s = r.map(txt).join(' ');
        let m = s.match(/date downloaded\s+([a-z]{3})[- ](\d{1,2})[-, ]+(\d{4})/i);
        if (m) return `${m[3]}-${pad(MONTHS.indexOf(m[1].toLowerCase()) + 1)}-${pad(+m[2])}`;
        m = s.match(/(?:as of|date downloaded)\s+(\d{1,2})\/(\d{1,2})\/(\d{4})/i);
        if (m) return `${m[3]}-${pad(+m[1])}-${pad(+m[2])}`;
    }
    return null;
}

function parseHoldings(t: Table, hi: number): Parsed {
    const H = t[hi].map(low);
    const col = (...res: RegExp[]) => {
        for (const re of res) {
            const i = H.findIndex((h) => re.test(h));
            if (i >= 0) return i;
        }
        return -1;
    };
    const c = {
        account: col(/^account (number|name)$/, /^account$/),
        accountName: col(/^account name$/),
        symbol: col(/^symbol$/, /ticker/),
        name: col(/^description$/, /^(fund|investment)( name)?$/, /^name$/, /description|fund|investment/),
        qty: col(/^quantity/, /^shares/, /^units/),
        price: col(/^last price$/, /^(share |unit )?price/, /price/),
        value: col(/^current value$/, /market value/, /^value$/, /ending balance/, /^balance$/),
        cost: col(/^cost basis total$/, /cost basis/, /^cost$/),
    };
    const rows: HoldingRow[] = [];
    for (const r of t.slice(hi + 1)) {
        const value = parseAmount(r[c.value]);
        let symbol = c.symbol >= 0 ? txt(r[c.symbol]).replace(/\*+$/, '') : '';
        let name = c.name >= 0 ? txt(r[c.name]) : '';
        if (value === null || (!symbol && !name)) continue;
        if (/^total|^account total/i.test(symbol + name)) continue;
        if (/^pending activity$/i.test(symbol) || /^pending activity$/i.test(name)) {
            name = 'Pending activity';
            symbol = '';
        }
        if (!name) name = symbol;
        if (/money market/i.test(name) || /^(spaxx|fdrxx|fzfxx|core)$/i.test(symbol)) name = name || 'Cash';
        const acct = [c.account >= 0 ? txt(r[c.account]) : '', c.accountName >= 0 && c.accountName !== c.account ? txt(r[c.accountName]) : '']
            .filter(Boolean)
            .join(' · ');
        rows.push({
            symbol: symbol ? symbol.slice(0, 20) : null,
            name: name.slice(0, 150),
            quantity: c.qty >= 0 ? num(r[c.qty]) : null,
            price: c.price >= 0 ? num(r[c.price]) : null,
            value,
            cost: c.cost >= 0 ? parseAmount(r[c.cost]) : null,
            account: acct,
        });
    }
    if (!rows.length) throw new Error('No holdings with a value were found in it.');
    const accounts = [...new Set(rows.map((r) => r.account))].filter(Boolean);
    return { type: 'holdings', source: c.account >= 0 ? 'Fidelity' : 'file', rows, asOf: downloadedDate(t), accounts };
}

function parseBalances(t: Table, hi: number): Parsed {
    const H = t[hi].map(low);
    const col = (...res: RegExp[]) => {
        for (const re of res) {
            const i = H.findIndex((h) => re.test(h));
            if (i >= 0) return i;
        }
        return -1;
    };
    const c = {
        date: col(/(^|statement |as of )date$/, /^period end/),
        balance: col(/^(ending |total |account )?balance$/, /^ending balance/, /total value/, /^value$/, /balance/),
        loan: col(/loan/),
        moneyIn: col(/money in/, /contribution/, /deposit/),
    };
    const body = t.slice(hi + 1);
    const fmt = guessDateFormat(body.map((r) => r[c.date]));
    let skipped = 0;
    const rows: BalanceRow[] = [];
    for (const r of body) {
        const date = parseDate(r[c.date], fmt);
        const balance = parseAmount(r[c.balance]);
        if (!date || balance === null) {
            skipped++;
            continue;
        }
        const loan = c.loan >= 0 && c.loan !== c.balance ? parseAmount(r[c.loan]) : null;
        const moneyIn = c.moneyIn >= 0 ? parseAmount(r[c.moneyIn]) : null;
        rows.push({ date, balance: Math.abs(balance), loan: loan === null ? null : Math.abs(loan), money_in: moneyIn });
    }
    if (!rows.length) throw new Error('No dates with a balance were found in it.');
    rows.sort((a, b) => (a.date < b.date ? -1 : 1));
    return { type: 'balances', source: 'file', rows, skipped };
}
