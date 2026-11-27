const whole = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });

/** Cents as whole dollars: "$1,235" or "−$1,235". */
export const dollars = (cents: number) => (cents < 0 ? '−' : '') + whole.format(Math.abs(cents) / 100);

/** Cents as whole dollars with a sign: "+$1,235" or "−$1,235". */
export const signedDollars = (cents: number) => (cents >= 0 ? '+' : '−') + whole.format(Math.abs(cents) / 100);

/** 0.123 -> "12%" (one decimal under 10%). */
export const percent = (v: number | null, signed = false) => {
    if (v === null) return '—';
    const a = Math.abs(v * 100);
    const s = a < 10 ? a.toFixed(1) : Math.round(a).toString();
    return (v < 0 ? '−' : signed ? '+' : '') + s + '%';
};

/** Short axis labels: 12345600 cents -> "$123k". */
export const axisDollars = (cents: number) => {
    const d = cents / 100;
    const a = Math.abs(d);
    const s = a >= 1_000_000 ? `$${(a / 1_000_000).toFixed(1)}m` : a >= 1000 ? `$${Math.round(a / 1000)}k` : `$${Math.round(a)}`;
    return (d < 0 ? '−' : '') + s;
};

/** Round gridlines covering lo..hi (always including zero). */
export function niceScale(lo: number, hi: number, count = 4) {
    lo = Math.min(lo, 0);
    hi = Math.max(hi, 1);
    const raw = (hi - lo) / count;
    const mag = Math.pow(10, Math.floor(Math.log10(raw)));
    const step = [1, 2, 2.5, 5, 10].map((f) => f * mag).find((s) => s >= raw) ?? raw;
    const min = Math.floor(lo / step) * step;
    const max = Math.ceil(hi / step) * step;
    const ticks: number[] = [];
    for (let v = min; v <= max + step / 2; v += step) ticks.push(Math.round(v));
    return { min, max, ticks };
}
