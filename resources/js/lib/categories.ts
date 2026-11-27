export interface KindOption {
    id: number;
    label: string;
    parent_id: number | null;
    kind: string;
}

const titles: Record<string, string> = { income: 'Income', expense: 'Spending', transfer: 'Transfers between your accounts' };

/** Category options split into Income, Spending and Transfers, in that order, for <optgroup>s. */
export function groupByKind<T extends KindOption>(options: T[]): { kind: string; title: string; options: T[] }[] {
    const groups: { kind: string; title: string; options: T[] }[] = [];
    for (const o of options) {
        let g = groups.find((x) => x.kind === o.kind);
        if (!g) groups.push((g = { kind: o.kind, title: titles[o.kind] ?? o.kind, options: [] }));
        g.options.push(o);
    }
    return groups;
}
