/**
 * Several bank files at once: works out which account each file belongs to.
 * Signals, strongest first: rows the account already has, the file name,
 * payees the account has paid before, and the column layout used last time.
 */
import type { Settings } from '@/lib/importer';

export interface MatchAccount {
    id: number;
    name: string;
    import_settings: Settings | null;
}
export interface MatchCounts {
    exact: Record<number, number>;
    familiar: Record<number, number>;
}
export interface Guess {
    accountId: number | null;
    reason: string;
}

const GENERIC = new Set(['account', 'card', 'credit', 'the', 'and', 'bank']);
// "Saphire" and "Sapphire" should match: compare words with doubled letters collapsed.
const squash = (w: string) => w.replace(/(.)\1+/g, '$1');
const words = (s: string) =>
    s
        .toLowerCase()
        .split(/[^a-z0-9]+/)
        .filter((w) => w.length >= 3 || /^\d{4}$/.test(w))
        .map(squash);

/** How many of the account's name words appear in the file name, and whether all of them do. */
export function nameScore(fileName: string, accountName: string): { hits: number; all: boolean } {
    const f = new Set(words(fileName.replace(/\.[a-z]+$/i, '')));
    const a = words(accountName).filter((w) => !GENERIC.has(w));
    const hits = a.filter((w) => f.has(w)).length;
    return { hits, all: a.length > 0 && hits === a.length };
}

function best(scores: [number, number][]): [number, number, number] | null {
    const s = [...scores].sort((x, y) => y[1] - x[1]);
    if (!s.length || s[0][1] <= 0) return null;
    return [s[0][0], s[0][1], s[1]?.[1] ?? 0];
}

export function guessAccount(fileName: string, headers: string[], rowCount: number, counts: MatchCounts | null, accounts: MatchAccount[]): Guess {
    const plural = (n: number, w: string) => `${n} ${w}${n === 1 ? '' : 's'}`;
    if (counts) {
        const e = best(accounts.map((a) => [a.id, counts.exact[a.id] ?? 0]));
        if (e && e[1] >= 3 && e[1] >= 2 * e[2]) return { accountId: e[0], reason: `It already has ${plural(e[1], 'transaction')} from this file` };
    }
    const names = accounts.map((a) => ({ a, ...nameScore(fileName, a.name) }));
    const full = names.filter((n) => n.all);
    const top = full.length ? Math.max(...full.map((n) => n.hits)) : 0;
    const fullTop = full.filter((n) => n.hits === top);
    if (fullTop.length === 1) return { accountId: fullTop[0].a.id, reason: 'The file name matches' };
    if (counts) {
        const f = best(accounts.map((a) => [a.id, counts.familiar[a.id] ?? 0]));
        if (f && f[1] >= Math.max(3, rowCount * 0.3) && f[1] >= 1.5 * f[2]) {
            return { accountId: f[0], reason: `${Math.round((f[1] / Math.max(1, rowCount)) * 100)}% of its payees are ones this account pays` };
        }
    }
    const sameLayout = accounts.filter((a) => a.import_settings && JSON.stringify(a.import_settings.headers) === JSON.stringify(headers));
    if (sameLayout.length === 1) return { accountId: sameLayout[0].id, reason: 'Same columns as your last import into it' };
    return { accountId: null, reason: 'Pick the account for this file' };
}
