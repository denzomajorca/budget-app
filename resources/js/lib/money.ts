const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

/** Formats cents as "$1,234.56" or "−$1,234.56". */
export function money(cents: number): string {
    const s = fmt.format(Math.abs(cents) / 100);
    return cents < 0 ? '−' + s : s;
}

/** Cents to a plain typed value, without sign: 123456 -> "1234.56". */
export function centsToInput(cents: number): string {
    return (Math.abs(cents) / 100).toFixed(2);
}

/** "2026-11-26" -> "Nov 26, 2026" without time zone shifts. */
export function shortDate(iso: string): string {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export function today(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
