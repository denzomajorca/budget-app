<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import InvestmentImportDialog from '@/components/InvestmentImportDialog.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { money, shortDate, today } from '@/lib/money';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { FileUp, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Acct {
    id: number;
    name: string;
    kind: string;
    institution: string | null;
    started_empty: boolean;
}
interface Row {
    id: number;
    name: string;
    kind_label: string;
    institution: string | null;
    value: number | null;
    value_date: string | null;
    start: number | null;
    start_date: string | null;
    put_in: number | null;
    gain: number | null;
    return: number | null;
    annual: number | null;
    partial: boolean;
    opened: boolean;
    loan: { amount: number; date: string } | null;
    target_stock_share: number | null;
    needs: 'balance' | 'second_balance' | null;
}
interface Fund {
    name: string;
    units: number;
    cost: number;
    avg_price: number | null;
    last_price: number;
    last_date: string;
    first_date: string;
    value: number;
    gain: number;
    price_change: number | null;
}
interface Mix {
    us_stock: number;
    intl_stock: number;
    mix_stock: number;
    bond: number;
    cash: number;
    other: number;
    stocks: number;
}
interface HoldingsAccount {
    mix: Mix;
    target: { stock_share: number; current: number; move: number } | null;
    id: number;
    name: string;
    as_of: string;
    total: number;
    rows: {
        id: number;
        symbol: string | null;
        name: string;
        quantity: number | null;
        price: number | null;
        value: number;
        cost: number | null;
        share: number;
        key: string;
        asset_class: string;
        stock_share: number;
    }[];
}
const props = defineProps<{
    filters: { tf: string; account: number | null };
    timeframes: Record<string, string>;
    timeframe: { key: string; label: string; from: string | null; to: string };
    kinds: Record<string, string>;
    assetClasses: Record<string, string>;
    activityKinds: Record<string, { label: string; flow: string }>;
    accounts: Acct[];
    rows: Row[];
    summary: {
        value: number;
        start: number | null;
        put_in: number | null;
        gain: number | null;
        return: number | null;
        annual: number | null;
        start_date: string | null;
        counted: number;
        with_value: number;
    };
    chart: { date: string; value: number; basis: number }[];
    activity: {
        by_kind: Record<string, number>;
        put_in: number;
        pay_dates: number;
        first: string | null;
        last: string | null;
        months: { month: string; employee: number; employer: number; loan_repayment: number; deposit: number; out: number; earnings: number }[];
        funds: Fund[];
    };
    holdings: {
        accounts: HoldingsAccount[];
        combined: { symbol: string | null; name: string; value: number; share: number }[];
        total: number;
        mix: Mix;
    };
    balances: { id: number; account_id: number; date: string; balance: number; loan_balance: number | null; source: string }[];
    recent: {
        id: number;
        account_id: number;
        date: string;
        kind: string;
        description: string;
        fund: string | null;
        amount: number;
        units: number | null;
        price: number | null;
        imported: boolean;
    }[];
    activityCount: number;
    imports: { id: number; account_id: number; file_name: string; type: string; added: number; skipped: number; date: string }[];
}>();

const narrow = useMediaQuery('(max-width: 640px)');
const whole = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
const m0 = (cents: number) => (cents < 0 ? '−' : '') + whole.format(Math.abs(cents) / 100);
const signed0 = (cents: number) => (cents >= 0 ? '+' : '−') + whole.format(Math.abs(cents) / 100);
const pct = (v: number | null) => (v === null ? '—' : `${v >= 0 ? '+' : '−'}${Math.abs(v * 100).toFixed(1)}%`);
const share = (v: number) => `${(v * 100).toFixed(v < 0.1 ? 1 : 0)}%`;
const monthLabel = (m: string) => {
    const [y, mo] = m.split('-').map(Number);
    return new Date(y, mo - 1, 1).toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
};
const acctName = (id: number) => props.accounts.find((a) => a.id === id)?.name ?? '';
const multi = computed(() => !props.filters.account && props.accounts.length > 1);

// Filters and tabs
function go(params: { tf?: string; account?: number | null }) {
    const q = { tf: params.tf ?? props.filters.tf, account: params.account === undefined ? props.filters.account : params.account };
    router.get(
        route('investments.index'),
        { tf: q.tf, ...(q.account ? { account: q.account } : {}) },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
const accountPick = ref<number | null>(props.filters.account);
watch(
    () => props.filters.account,
    (v) => (accountPick.value = v),
);
type Tab = 'performance' | 'activity' | 'holdings';
const readTab = (): Tab => {
    try {
        const v = localStorage.getItem('investments.tab');
        return v === 'activity' || v === 'holdings' ? v : 'performance';
    } catch {
        return 'performance';
    }
};
const tab = ref<Tab>(readTab());
watch(tab, (v) => {
    try {
        localStorage.setItem('investments.tab', v);
    } catch {
        /* private window */
    }
});

// Chart: account value against starting value plus money put in.
const W = computed(() => (narrow.value ? 360 : 640));
const H = computed(() => (narrow.value ? 240 : 220));
const pad = computed(() => ({ l: narrow.value ? 46 : 56, r: 12, t: 12, b: 24 }));
const scale = computed(() => {
    const all = props.chart.flatMap((p) => [p.value, p.basis]);
    const max = Math.max(...all, 1);
    const min = Math.min(...all, 0);
    const raw = (max - min) / 4;
    const mag = Math.pow(10, Math.floor(Math.log10(Math.max(raw, 1))));
    const step = [1, 2, 2.5, 5, 10].map((f) => f * mag).find((s) => s >= raw) ?? raw;
    const lo = Math.floor(min / step) * step;
    const hi = Math.ceil(max / step) * step || step;
    const ticks: number[] = [];
    for (let v = lo; v <= hi + 1; v += step) ticks.push(v);
    return { lo, hi, ticks };
});
const cx = (i: number) => pad.value.l + (i * (W.value - pad.value.l - pad.value.r)) / Math.max(1, props.chart.length - 1);
const cy = (v: number) => pad.value.t + ((scale.value.hi - v) / (scale.value.hi - scale.value.lo || 1)) * (H.value - pad.value.t - pad.value.b);
const line = (key: 'value' | 'basis') => props.chart.map((p, i) => `${cx(i)},${cy(p[key])}`).join(' ');
const kfmt = (cents: number) => {
    const d = cents / 100;
    return Math.abs(d) >= 1000 ? `$${Math.round(d / 1000)}k` : `$${Math.round(d)}`;
};
const xLabels = computed(() => {
    const n = props.chart.length;
    const step = Math.ceil(n / (narrow.value ? 4 : 7));
    return props.chart
        .map((p, i) => ({ i, label: new Date(p.date + 'T12:00:00').toLocaleDateString('en-US', { month: 'short', year: '2-digit' }) }))
        .filter((p) => p.i % step === 0 || p.i === n - 1)
        .filter((p, k, arr) => !(k === arr.length - 2 && arr[k + 1].i - p.i < step / 2));
});

// Account dialog
const acctOpen = ref(false);
const acctEditing = ref<Acct | null>(null);
const confirmDelete = ref(false);
const acctForm = useForm({ name: '', kind: '401k', institution: '', started_empty: false });
function addAccount(preset?: { name: string; kind: string; institution: string }) {
    acctEditing.value = null;
    confirmDelete.value = false;
    acctForm.clearErrors();
    acctForm.name = preset?.name ?? '';
    acctForm.kind = preset?.kind ?? '401k';
    acctForm.institution = preset?.institution ?? '';
    acctForm.started_empty = preset?.kind === 'brokerage';
    acctOpen.value = true;
}
function editAccount(a: Acct) {
    acctEditing.value = a;
    confirmDelete.value = false;
    acctForm.clearErrors();
    acctForm.name = a.name;
    acctForm.kind = a.kind;
    acctForm.institution = a.institution ?? '';
    acctForm.started_empty = a.started_empty;
    acctOpen.value = true;
}
function saveAccount() {
    const o = { preserveScroll: true, onSuccess: () => (acctOpen.value = false) };
    if (acctEditing.value) acctForm.put(route('investments.accounts.update', acctEditing.value.id), o);
    else acctForm.post(route('investments.accounts.store'), o);
}
function deleteAccount() {
    if (acctEditing.value) router.delete(route('investments.accounts.destroy', acctEditing.value.id), { onSuccess: () => (acctOpen.value = false) });
}
const selectedAccount = computed(() => props.accounts.find((a) => a.id === props.filters.account) ?? null);

// Balance dialog
const balOpen = ref(false);
const balForm = useForm({ account_id: null as number | null, date: today(), balance: '', loan_balance: '' });
const balIs401k = computed(() => props.accounts.find((a) => a.id === balForm.account_id)?.kind === '401k');
function addBalance(accountId?: number) {
    balForm.clearErrors();
    balForm.account_id = accountId ?? props.filters.account ?? props.accounts[0]?.id ?? null;
    balForm.date = today();
    balForm.balance = '';
    balForm.loan_balance = '';
    balOpen.value = true;
}
function saveBalance() {
    balForm.post(route('investments.balances.store'), { preserveScroll: true, onSuccess: () => (balOpen.value = false) });
}
const removeBalance = (id: number) => router.delete(route('investments.balances.destroy', id), { preserveScroll: true });

// Activity dialog
const actOpen = ref(false);
const actForm = useForm({ account_id: null as number | null, date: today(), kind: 'deposit', amount: '', description: '' });
function addActivity() {
    actForm.clearErrors();
    actForm.account_id = props.filters.account ?? props.accounts[0]?.id ?? null;
    actForm.date = today();
    actForm.kind = 'deposit';
    actForm.amount = '';
    actForm.description = '';
    actOpen.value = true;
}
function saveActivity() {
    actForm.post(route('investments.activity.store'), { preserveScroll: true, onSuccess: () => (actOpen.value = false) });
}
const removeActivity = (id: number) => router.delete(route('investments.activity.destroy', id), { preserveScroll: true });
const undoImport = (id: number) => router.delete(route('investments.import.destroy', id), { preserveScroll: true });

// Typed holdings
const holdOpen = ref(false);
const holdErr = ref('');
const holdForm = ref({ account_id: null as number | null, as_of: today(), total: '', rows: [] as { name: string; pct: string; value: string }[] });
const toNum = (v: string) => parseFloat(v.replace(/[$,%\s]/g, '')) || 0;
function pctTyped(r: { pct: string; value: string }) {
    const t = toNum(holdForm.value.total);
    if (t && r.pct) r.value = ((t * toNum(r.pct)) / 100).toFixed(2);
}
function totalTyped() {
    holdForm.value.rows.forEach(pctTyped);
}
const pctSum = computed(() => holdForm.value.rows.reduce((s, r) => s + toNum(r.pct), 0));
function typeHoldings() {
    holdErr.value = '';
    const id = props.filters.account ?? props.accounts[0]?.id ?? null;
    const cur = props.holdings.accounts.find((a) => a.id === id);
    holdForm.value = {
        account_id: id,
        as_of: today(),
        total: '',
        rows: cur
            ? cur.rows.map((r) => ({ name: r.name, pct: '', value: (r.value / 100).toFixed(2) }))
            : [
                  { name: '', pct: '', value: '' },
                  { name: '', pct: '', value: '' },
              ],
    };
    holdOpen.value = true;
}
const holdTotal = computed(() => holdForm.value.rows.reduce((s, r) => s + toNum(r.value), 0));
function saveHoldings() {
    const rows = holdForm.value.rows.map((r) => ({ name: r.name.trim(), value: Math.round(toNum(r.value) * 100) })).filter((r) => r.name && r.value);
    if (!rows.length) {
        holdErr.value = 'Type at least one fund and its value.';
        return;
    }
    router.post(
        route('investments.import'),
        { account_id: holdForm.value.account_id, file_name: 'Typed in', type: 'holdings', as_of: holdForm.value.as_of, rows } as never,
        {
            preserveScroll: true,
            onSuccess: () => (holdOpen.value = false),
            onError: (e) => (holdErr.value = Object.values(e)[0] ?? 'Could not save.'),
        },
    );
}

// Stocks and bonds
const mixParts = (m: Mix) =>
    [
        { key: 'us_stock', label: 'U.S. stocks', cls: 'bg-sky-600', value: m.us_stock },
        { key: 'intl_stock', label: 'International stocks', cls: 'bg-sky-400', value: m.intl_stock },
        { key: 'mix_stock', label: 'Stocks inside mixed funds', cls: 'bg-indigo-500', value: m.mix_stock },
        { key: 'bond', label: 'Bonds', cls: 'bg-amber-500', value: m.bond },
        { key: 'cash', label: 'Cash', cls: 'bg-emerald-600', value: m.cash },
        { key: 'other', label: 'Other', cls: 'bg-slate-400', value: m.other },
    ].filter((p) => p.value > 0);
const mixTotal = (m: Mix) => m.us_stock + m.intl_stock + m.mix_stock + m.bond + m.cash + m.other;
function setClass(key: string, asset_class: string, stock_share?: number) {
    router.post(
        route('investments.funds.store'),
        { key, asset_class, stock_share: stock_share ?? null },
        { preserveScroll: true, preserveState: true },
    );
}
const targetInput = ref<Record<number, string>>({});
function saveTarget(accountId: number, clear = false) {
    const v = clear ? null : Math.round(toNum(targetInput.value[accountId] ?? ''));
    router.put(route('investments.accounts.target', accountId), { target_stock_share: v }, { preserveScroll: true, preserveState: true });
}
const targetOf = (id: number) => props.accounts.find((a) => a.id === id)?.target_stock_share ?? null;

const importOpen = ref(false);
const showAll = ref(false);
const recentShown = computed(() => (showAll.value ? props.recent : props.recent.slice(0, 40)));
const colors = ['bg-sky-600', 'bg-amber-500', 'bg-violet-500', 'bg-emerald-600', 'bg-rose-500', 'bg-slate-500'];
const flowIn = computed(() => {
    const k = props.activity.by_kind;
    return {
        employee: k.employee ?? 0,
        employer: k.employer ?? 0,
        loan: k.loan_repayment ?? 0,
        deposit: k.deposit ?? 0,
        out: (k.withdrawal ?? 0) + (k.loan ?? 0),
    };
});
const has401k = computed(() => flowIn.value.employee !== 0 || flowIn.value.employer !== 0 || flowIn.value.loan !== 0);
</script>

<template>
    <Head title="Investments" />
    <AppLayout :breadcrumbs="[{ title: 'Investments', href: '/investments' }]">
        <div class="mx-auto flex w-full min-w-0 max-w-6xl flex-1 flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-semibold">Investments</h1>
                <div v-if="accounts.length" class="flex flex-wrap gap-2">
                    <Button @click="importOpen = true"><FileUp class="size-4" /> Import file</Button>
                    <Button variant="outline" @click="addBalance()"><Plus class="size-4" /> Statement balance</Button>
                    <Button variant="outline" @click="addAccount()"><Plus class="size-4" /> Account</Button>
                </div>
            </div>

            <Card v-if="!accounts.length">
                <CardHeader>
                    <CardTitle>Track your 401(k) and other investments</CardTitle>
                    <CardDescription
                        >Add each investment account, then import its files or type a balance from a statement. You’ll see what you put in, what your
                        employer added, how much the markets added on top, and what each account holds.</CardDescription
                    >
                </CardHeader>
                <CardContent class="flex flex-wrap gap-2">
                    <Button @click="addAccount({ name: 'John Hancock 401(k)', kind: '401k', institution: 'John Hancock' })"
                        ><Plus class="size-4" /> John Hancock 401(k)</Button
                    >
                    <Button variant="outline" @click="addAccount({ name: 'Fidelity brokerage', kind: 'brokerage', institution: 'Fidelity' })"
                        ><Plus class="size-4" /> Fidelity account</Button
                    >
                    <Button variant="outline" @click="addAccount()"><Plus class="size-4" /> Another account</Button>
                </CardContent>
            </Card>

            <template v-else>
                <div class="flex flex-wrap items-center gap-2">
                    <NativeSelect v-model="accountPick" class="w-auto min-w-0 max-w-full" aria-label="Account" @change="go({ account: accountPick })">
                        <option :value="null">All accounts</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </NativeSelect>
                    <Button v-if="selectedAccount" variant="ghost" size="sm" @click="editAccount(selectedAccount)"
                        ><Pencil class="size-4" /> Edit account</Button
                    >
                    <div class="inline-flex flex-wrap rounded-md border p-0.5 text-sm" role="group" aria-label="Timeframe">
                        <button
                            v-for="(l, k) in timeframes"
                            :key="k"
                            type="button"
                            class="rounded px-3 py-1.5"
                            :class="filters.tf === k ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                            :aria-pressed="filters.tf === k"
                            @click="go({ tf: k })"
                        >
                            {{ narrow ? { ytd: 'YTD', '1y': '1 year', '3y': '3 years', all: 'All' }[k] : l }}
                        </button>
                    </div>
                </div>

                <div class="inline-flex w-fit max-w-full flex-wrap rounded-md border p-0.5 text-sm" role="tablist" aria-label="Investments view">
                    <button
                        v-for="[k, l] in [
                            ['performance', 'Performance'],
                            ['activity', 'Contributions'],
                            ['holdings', 'Holdings'],
                        ] as const"
                        :key="k"
                        type="button"
                        role="tab"
                        :aria-selected="tab === k"
                        class="rounded px-3 py-1.5"
                        :class="tab === k ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                        @click="tab = k"
                    >
                        {{ l }}
                    </button>
                </div>

                <!-- Performance -->
                <template v-if="tab === 'performance'">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3 [&>*]:min-w-0">
                        <Card>
                            <CardHeader class="pb-2">
                                <CardDescription>Value now</CardDescription>
                                <CardTitle class="text-2xl tabular-nums">{{ summary.with_value ? m0(summary.value) : '—' }}</CardTitle>
                            </CardHeader>
                            <CardContent class="text-sm text-muted-foreground">
                                <template v-if="summary.start !== null">was {{ m0(summary.start) }} on {{ shortDate(summary.start_date!) }}</template>
                                <template v-else-if="summary.with_value">from the latest balance you saved</template>
                                <template v-else>Save a statement balance to see this</template>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader class="pb-2">
                                <CardDescription>Money you put in</CardDescription>
                                <CardTitle class="text-2xl tabular-nums">{{ summary.put_in !== null ? m0(summary.put_in) : '—' }}</CardTitle>
                            </CardHeader>
                            <CardContent class="text-sm text-muted-foreground"
                                >contributions, employer match and loan repayments, less withdrawals</CardContent
                            >
                        </Card>
                        <Card>
                            <CardHeader class="pb-2">
                                <CardDescription>Investment growth</CardDescription>
                                <CardTitle
                                    class="text-2xl tabular-nums"
                                    :class="
                                        summary.gain === null ? '' : summary.gain >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'
                                    "
                                >
                                    {{ summary.gain !== null ? signed0(summary.gain) : '—' }}
                                </CardTitle>
                            </CardHeader>
                            <CardContent class="text-sm text-muted-foreground">
                                <template v-if="summary.gain !== null"
                                    >{{ pct(summary.return) }} return<template v-if="summary.annual !== null"
                                        >, {{ pct(summary.annual) }} a year</template
                                    ></template
                                >
                                <template v-else>Needs two balances on different dates</template>
                            </CardContent>
                        </Card>
                    </div>

                    <Card v-if="chart.length >= 2">
                        <CardHeader class="pb-2">
                            <CardTitle class="text-base">{{ timeframe.label }}</CardTitle>
                            <CardDescription>The gap between the two lines is what the markets added on top of your own money.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <svg :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img" aria-label="Investment value compared with money put in">
                                <g v-for="t in scale.ticks" :key="t">
                                    <line :x1="pad.l" :x2="W - pad.r" :y1="cy(t)" :y2="cy(t)" class="stroke-border" />
                                    <text :x="pad.l - 6" :y="cy(t) + 4" text-anchor="end" class="fill-muted-foreground text-[11px]">
                                        {{ kfmt(t) }}
                                    </text>
                                </g>
                                <text
                                    v-for="p in xLabels"
                                    :key="p.i"
                                    :x="cx(p.i)"
                                    :y="H - 6"
                                    text-anchor="middle"
                                    class="fill-muted-foreground text-[11px]"
                                >
                                    {{ p.label }}
                                </text>
                                <polyline
                                    :points="line('basis')"
                                    fill="none"
                                    class="stroke-muted-foreground"
                                    stroke-width="2"
                                    stroke-dasharray="5 4"
                                />
                                <polyline :points="line('value')" fill="none" class="stroke-sky-600" stroke-width="2.5" stroke-linejoin="round" />
                                <circle :cx="cx(chart.length - 1)" :cy="cy(chart[chart.length - 1].value)" r="4" class="fill-sky-600" />
                            </svg>
                            <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground">
                                <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 bg-sky-600" /> Account value</span>
                                <span class="inline-flex items-center gap-1.5"
                                    ><span class="w-4 border-t-2 border-dashed border-muted-foreground" /> Starting value plus money you put in</span
                                >
                            </div>
                            <p class="mt-2 text-xs text-muted-foreground">
                                Between two saved balances the value line only adds the money you put in; it catches up with the markets when you save
                                the next balance or import a holdings file.
                            </p>
                        </CardContent>
                    </Card>

                    <div class="w-0 min-w-full overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Account</th>
                                    <th class="hidden px-3 py-2 text-right font-medium sm:table-cell">Start</th>
                                    <th class="hidden px-3 py-2 text-right font-medium sm:table-cell">Put in</th>
                                    <th class="px-3 py-2 text-right font-medium">Growth</th>
                                    <th class="px-3 py-2 text-right font-medium">Return</th>
                                    <th class="px-3 py-2 text-right font-medium">Now</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in rows" :key="r.id" class="border-t align-top">
                                    <td class="px-3 py-2">
                                        <button
                                            type="button"
                                            class="text-left font-medium underline-offset-4 hover:underline"
                                            @click="go({ account: r.id })"
                                        >
                                            {{ r.name }}
                                        </button>
                                        <div class="text-xs text-muted-foreground">
                                            <template v-if="r.needs === 'balance'">
                                                No balance yet.
                                                <button type="button" class="underline" @click="addBalance(r.id)">Add one</button>
                                            </template>
                                            <template v-else-if="r.needs === 'second_balance'">
                                                One balance so far ({{ shortDate(r.value_date!) }}). Add a later one to see growth.
                                            </template>
                                            <template v-else
                                                >{{ shortDate(r.start_date!) }} to {{ shortDate(r.value_date!)
                                                }}<template v-if="r.opened"> (since it opened)</template
                                                ><template v-else-if="r.partial"> (your first balance)</template></template
                                            >
                                        </div>
                                        <div v-if="r.loan && r.loan.amount > 0" class="text-xs text-amber-700 dark:text-amber-400">
                                            Loan still owed {{ money(r.loan.amount) }} ({{ shortDate(r.loan.date) }})
                                        </div>
                                    </td>
                                    <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{{ r.start !== null ? m0(r.start) : '' }}</td>
                                    <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">
                                        {{ r.put_in !== null ? m0(r.put_in) : '' }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                        :class="r.gain === null ? '' : r.gain >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'"
                                    >
                                        {{ r.gain !== null ? signed0(r.gain) : '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ pct(r.return) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ r.value !== null ? m0(r.value) : '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <Card>
                        <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0 pb-2">
                            <div>
                                <CardTitle class="text-base">Statement balances</CardTitle>
                                <CardDescription>The total value on a date, typed from a statement or added up from a holdings file.</CardDescription>
                            </div>
                            <Button variant="outline" size="sm" @click="addBalance()"><Plus class="size-4" /> Add</Button>
                        </CardHeader>
                        <CardContent>
                            <p v-if="!balances.length" class="text-sm text-muted-foreground">
                                None yet. Type the total from your latest John Hancock statement, or import a Fidelity Positions file.
                            </p>
                            <ul v-else class="divide-y text-sm">
                                <li v-for="b in balances" :key="b.id" class="flex items-center justify-between gap-3 py-2">
                                    <span class="min-w-0">
                                        {{ shortDate(b.date) }}
                                        <span v-if="multi" class="text-muted-foreground"> · {{ acctName(b.account_id) }}</span>
                                        <span v-if="b.source === 'holdings'" class="text-muted-foreground"> · from holdings</span>
                                        <span v-if="b.loan_balance" class="text-muted-foreground"> · loan owed {{ money(b.loan_balance) }}</span>
                                    </span>
                                    <span class="flex items-center gap-2">
                                        <span class="tabular-nums">{{ money(b.balance) }}</span>
                                        <Button variant="ghost" size="icon" class="size-8" aria-label="Remove balance" @click="removeBalance(b.id)"
                                            ><Trash2 class="size-4"
                                        /></Button>
                                    </span>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                    <p class="text-xs text-muted-foreground">
                        Growth is the change in value minus the money you put in. Return divides it by the starting value plus half of what you added
                        along the way. Loan repayments count as money you put in.
                    </p>
                </template>

                <!-- Contributions and activity -->
                <template v-else-if="tab === 'activity'">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3 [&>*]:min-w-0">
                        <template v-if="has401k">
                            <Card>
                                <CardHeader class="pb-2">
                                    <CardDescription>You put in</CardDescription>
                                    <CardTitle class="text-2xl tabular-nums">{{ m0(flowIn.employee) }}</CardTitle>
                                </CardHeader>
                                <CardContent class="text-sm text-muted-foreground"
                                    >from your paychecks<template v-if="activity.pay_dates"
                                        >, {{ activity.pay_dates }} pay dates</template
                                    ></CardContent
                                >
                            </Card>
                            <Card>
                                <CardHeader class="pb-2">
                                    <CardDescription>Employer added</CardDescription>
                                    <CardTitle class="text-2xl tabular-nums">{{ m0(flowIn.employer) }}</CardTitle>
                                </CardHeader>
                                <CardContent class="text-sm text-muted-foreground">matching contributions</CardContent>
                            </Card>
                            <Card>
                                <CardHeader class="pb-2">
                                    <CardDescription>Loan repayments</CardDescription>
                                    <CardTitle class="text-2xl tabular-nums">{{ m0(flowIn.loan) }}</CardTitle>
                                </CardHeader>
                                <CardContent class="text-sm text-muted-foreground">paid back into the account</CardContent>
                            </Card>
                        </template>
                        <template v-else>
                            <Card>
                                <CardHeader class="pb-2">
                                    <CardDescription>Deposits</CardDescription>
                                    <CardTitle class="text-2xl tabular-nums">{{ m0(flowIn.deposit) }}</CardTitle>
                                </CardHeader>
                                <CardContent class="text-sm text-muted-foreground">money you added</CardContent>
                            </Card>
                            <Card>
                                <CardHeader class="pb-2">
                                    <CardDescription>Withdrawals</CardDescription>
                                    <CardTitle class="text-2xl tabular-nums">{{ m0(-flowIn.out) }}</CardTitle>
                                </CardHeader>
                                <CardContent class="text-sm text-muted-foreground">money you took out</CardContent>
                            </Card>
                            <Card>
                                <CardHeader class="pb-2">
                                    <CardDescription>Dividends and interest</CardDescription>
                                    <CardTitle class="text-2xl tabular-nums">{{ m0(activity.by_kind.earnings ?? 0) }}</CardTitle>
                                </CardHeader>
                                <CardContent class="text-sm text-muted-foreground">paid by what you own</CardContent>
                            </Card>
                        </template>
                    </div>
                    <p v-if="activity.first" class="-mt-2 text-sm text-muted-foreground">
                        {{ timeframe.label }}: {{ shortDate(activity.first) }} to {{ shortDate(activity.last!) }}
                    </p>

                    <Card v-if="activity.funds.length">
                        <CardHeader class="pb-2">
                            <CardTitle class="text-base">How the money added in this period has done</CardTitle>
                            <CardDescription>
                                {{ m0(activity.funds.reduce((s, f) => s + f.cost, 0)) }} bought fund shares. At each fund’s latest price in your
                                files, those shares are worth <b>{{ m0(activity.funds.reduce((s, f) => s + f.value, 0)) }}</b
                                >.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="p-0">
                            <div class="w-0 min-w-full overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="border-y bg-muted/50 text-left text-muted-foreground">
                                        <tr>
                                            <th class="px-3 py-2 font-medium">Fund</th>
                                            <th class="hidden px-3 py-2 text-right font-medium sm:table-cell">Shares bought</th>
                                            <th class="hidden px-3 py-2 text-right font-medium sm:table-cell">Avg price</th>
                                            <th class="px-3 py-2 text-right font-medium">Latest price</th>
                                            <th class="px-3 py-2 text-right font-medium">Worth now</th>
                                            <th class="px-3 py-2 text-right font-medium">Gain</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="f in activity.funds" :key="f.name" class="border-t align-top">
                                            <td class="px-3 py-2">
                                                {{ f.name }}
                                                <div class="text-xs text-muted-foreground">
                                                    price {{ pct(f.price_change) }} since {{ shortDate(f.first_date) }}
                                                </div>
                                            </td>
                                            <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{{ f.units.toFixed(2) }}</td>
                                            <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">
                                                {{ f.avg_price !== null ? money(Math.round(f.avg_price * 100)) : '' }}
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums">{{ money(Math.round(f.last_price * 100)) }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums">{{ m0(f.value) }}</td>
                                            <td
                                                class="px-3 py-2 text-right tabular-nums"
                                                :class="f.gain >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'"
                                            >
                                                {{ signed0(f.gain) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>

                    <div v-if="activity.months.length" class="w-0 min-w-full overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Month</th>
                                    <template v-if="has401k">
                                        <th class="px-3 py-2 text-right font-medium">You</th>
                                        <th class="px-3 py-2 text-right font-medium">Employer</th>
                                        <th class="px-3 py-2 text-right font-medium">Loan</th>
                                    </template>
                                    <template v-else>
                                        <th class="px-3 py-2 text-right font-medium">Deposits</th>
                                        <th class="px-3 py-2 text-right font-medium">Withdrawals</th>
                                        <th class="px-3 py-2 text-right font-medium">Dividends</th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="m in activity.months" :key="m.month" class="border-t">
                                    <td class="px-3 py-2">{{ monthLabel(m.month) }}</td>
                                    <template v-if="has401k">
                                        <td class="px-3 py-2 text-right tabular-nums">{{ money(m.employee) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ money(m.employer) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ money(m.loan_repayment) }}</td>
                                    </template>
                                    <template v-else>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ money(m.deposit) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ money(-m.out) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ money(m.earnings) }}</td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <Card>
                        <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0 pb-2">
                            <div>
                                <CardTitle class="text-base">Activity</CardTitle>
                                <CardDescription>{{ activityCount }} rows, newest first.</CardDescription>
                            </div>
                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" @click="importOpen = true"><FileUp class="size-4" /> Import</Button>
                                <Button variant="outline" size="sm" @click="addActivity"><Plus class="size-4" /> Type one in</Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <p v-if="!recent.length" class="text-sm text-muted-foreground">
                                Nothing yet. Import the John Hancock activity file or Fidelity’s history file, or type a deposit in.
                            </p>
                            <ul v-else class="divide-y text-sm">
                                <li v-for="x in recentShown" :key="x.id" class="flex items-start justify-between gap-3 py-2">
                                    <span class="min-w-0">
                                        <span class="block truncate">{{
                                            x.fund ? `${activityKinds[x.kind]?.label} · ${x.fund}` : x.description
                                        }}</span>
                                        <span class="text-xs text-muted-foreground"
                                            >{{ shortDate(x.date) }}<template v-if="multi"> · {{ acctName(x.account_id) }}</template
                                            ><template v-if="x.units && x.price">
                                                · {{ x.units.toFixed(3) }} shares at {{ money(Math.round(x.price * 100)) }}</template
                                            ><template v-if="!x.fund"> · {{ activityKinds[x.kind]?.label }}</template></span
                                        >
                                    </span>
                                    <span class="flex shrink-0 items-center gap-1">
                                        <span class="tabular-nums">{{ money(x.amount) }}</span>
                                        <Button
                                            v-if="!x.imported"
                                            variant="ghost"
                                            size="icon"
                                            class="size-8"
                                            aria-label="Remove"
                                            @click="removeActivity(x.id)"
                                            ><Trash2 class="size-4"
                                        /></Button>
                                    </span>
                                </li>
                            </ul>
                            <Button v-if="recent.length > 40 && !showAll" variant="ghost" size="sm" class="mt-2" @click="showAll = true"
                                >Show {{ recent.length - 40 }} more</Button
                            >
                        </CardContent>
                    </Card>

                    <Card v-if="imports.length">
                        <CardHeader class="pb-2">
                            <CardTitle class="text-base">Recent imports</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul class="divide-y text-sm">
                                <li v-for="i in imports" :key="i.id" class="flex items-center justify-between gap-3 py-2">
                                    <span class="min-w-0">
                                        <span class="block truncate">{{ i.file_name }}</span>
                                        <span class="text-xs text-muted-foreground"
                                            >{{ shortDate(i.date) }} · {{ acctName(i.account_id) }} · {{ i.added }}
                                            {{ i.type === 'holdings' ? 'holdings' : 'rows'
                                            }}<template v-if="i.skipped">, {{ i.skipped }} skipped</template></span
                                        >
                                    </span>
                                    <Button variant="ghost" size="sm" @click="undoImport(i.id)">Undo</Button>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                </template>

                <!-- Holdings -->
                <template v-else>
                    <Card v-if="!holdings.accounts.length">
                        <CardHeader>
                            <CardTitle class="text-base">What your accounts hold</CardTitle>
                            <CardDescription
                                >Import Fidelity’s Positions file, or type in each fund and its value from your John Hancock statement or website.
                                You’ll see how your money is split between funds.</CardDescription
                            >
                        </CardHeader>
                        <CardContent class="flex flex-wrap gap-2">
                            <Button @click="importOpen = true"><FileUp class="size-4" /> Import positions file</Button>
                            <Button variant="outline" @click="typeHoldings"><Pencil class="size-4" /> Type in holdings</Button>
                        </CardContent>
                    </Card>
                    <template v-else>
                        <Card v-if="holdings.accounts.length > 1">
                            <CardHeader class="pb-2">
                                <CardTitle class="text-base">All accounts together</CardTitle>
                                <CardDescription>{{ m0(holdings.total) }} across {{ holdings.accounts.length }} accounts</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <p class="mb-2 text-sm">
                                    <b>{{ share(holdings.mix.stocks / (mixTotal(holdings.mix) || 1)) }} stocks</b>,
                                    {{ share(1 - holdings.mix.stocks / (mixTotal(holdings.mix) || 1)) }} bonds and cash
                                </p>
                                <div class="mb-4 flex h-3 overflow-hidden rounded-full bg-muted">
                                    <div
                                        v-for="p in mixParts(holdings.mix)"
                                        :key="p.key"
                                        :class="p.cls"
                                        :style="{ width: `${(p.value / (mixTotal(holdings.mix) || 1)) * 100}%` }"
                                        :title="p.label"
                                    />
                                </div>
                                <div class="flex h-3 overflow-hidden rounded-full bg-muted">
                                    <div
                                        v-for="(c, i) in holdings.combined"
                                        :key="i"
                                        :class="colors[i % colors.length]"
                                        :style="{ width: `${c.share * 100}%` }"
                                        :title="c.name"
                                    />
                                </div>
                                <ul class="mt-3 grid gap-1 text-sm">
                                    <li v-for="(c, i) in holdings.combined" :key="i" class="flex items-center justify-between gap-3">
                                        <span class="flex min-w-0 items-center gap-2"
                                            ><span class="size-2.5 shrink-0 rounded-full" :class="colors[i % colors.length]" /><span
                                                class="truncate"
                                                >{{ c.symbol ? `${c.symbol} · ${c.name}` : c.name }}</span
                                            ></span
                                        >
                                        <span class="shrink-0 tabular-nums">{{ share(c.share) }} · {{ m0(c.value) }}</span>
                                    </li>
                                </ul>
                            </CardContent>
                        </Card>
                        <Card v-for="h in holdings.accounts" :key="h.id">
                            <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0 pb-2">
                                <div>
                                    <CardTitle class="text-base">{{ h.name }}</CardTitle>
                                    <CardDescription>{{ m0(h.total) }} as of {{ shortDate(h.as_of) }}</CardDescription>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div class="mb-4 grid gap-2 rounded-md border p-3">
                                    <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                                        <span
                                            ><b>Stocks vs bonds:</b> {{ share(h.mix.stocks / (h.total || 1)) }} stocks,
                                            {{ share(1 - h.mix.stocks / (h.total || 1)) }} bonds and cash</span
                                        >
                                        <span v-if="h.target" class="text-muted-foreground"
                                            >Target {{ h.target.stock_share }}/{{ 100 - h.target.stock_share }}</span
                                        >
                                    </div>
                                    <div class="relative">
                                        <div class="flex h-3 overflow-hidden rounded-full bg-muted">
                                            <div
                                                v-for="p in mixParts(h.mix)"
                                                :key="p.key"
                                                :class="p.cls"
                                                :style="{ width: `${(p.value / (mixTotal(h.mix) || 1)) * 100}%` }"
                                                :title="p.label"
                                            />
                                        </div>
                                        <div
                                            v-if="h.target"
                                            class="absolute -top-1 h-5 w-0.5 bg-foreground"
                                            :style="{ left: `calc(${h.target.stock_share}% - 1px)` }"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                        <span v-for="p in mixParts(h.mix)" :key="p.key" class="inline-flex items-center gap-1.5"
                                            ><span class="size-2.5 rounded-full" :class="p.cls" />{{ p.label }}
                                            {{ share(p.value / (mixTotal(h.mix) || 1)) }}</span
                                        >
                                        <span v-if="h.target" class="inline-flex items-center gap-1.5"
                                            ><span class="h-3 w-0.5 bg-foreground" /> your target</span
                                        >
                                    </div>
                                    <p v-if="h.target" class="text-sm">
                                        <template v-if="Math.abs(h.target.move) < h.total * 0.01">You’re on target.</template>
                                        <template v-else-if="h.target.move > 0"
                                            >To reach {{ h.target.stock_share }}/{{ 100 - h.target.stock_share }}, move about
                                            <b>{{ m0(h.target.move) }}</b> from stocks into bonds.</template
                                        >
                                        <template v-else
                                            >To reach {{ h.target.stock_share }}/{{ 100 - h.target.stock_share }}, move about
                                            <b>{{ m0(-h.target.move) }}</b> from bonds or cash into stocks.</template
                                        >
                                    </p>
                                    <form class="flex flex-wrap items-center gap-2 text-sm" @submit.prevent="saveTarget(h.id)">
                                        <Label :for="`tg-${h.id}`">Target in stocks</Label>
                                        <Input
                                            :id="`tg-${h.id}`"
                                            v-model="targetInput[h.id]"
                                            inputmode="numeric"
                                            class="h-8 w-20"
                                            :placeholder="targetOf(h.id) !== null ? String(targetOf(h.id)) : '65'"
                                        />
                                        <span>%</span>
                                        <Button type="submit" size="sm" variant="outline">Save target</Button>
                                        <Button v-if="h.target" type="button" size="sm" variant="ghost" @click="saveTarget(h.id, true)"
                                            >Remove</Button
                                        >
                                    </form>
                                </div>
                                <div class="flex h-3 overflow-hidden rounded-full bg-muted">
                                    <div
                                        v-for="(r, i) in h.rows"
                                        :key="r.id"
                                        :class="colors[i % colors.length]"
                                        :style="{ width: `${r.share * 100}%` }"
                                        :title="r.name"
                                    />
                                </div>
                                <div class="mt-3 w-0 min-w-full overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="text-left text-muted-foreground">
                                            <tr>
                                                <th class="py-1.5 pr-3 font-medium">Holding</th>
                                                <th class="hidden px-3 py-1.5 text-right font-medium sm:table-cell">Shares</th>
                                                <th class="hidden px-3 py-1.5 text-right font-medium sm:table-cell">Price</th>
                                                <th class="px-3 py-1.5 text-right font-medium">Value</th>
                                                <th class="py-1.5 pl-3 text-right font-medium">Share</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(r, i) in h.rows" :key="r.id" class="border-t align-top">
                                                <td class="py-1.5 pr-3">
                                                    <span class="flex items-start gap-2"
                                                        ><span
                                                            class="mt-1.5 size-2.5 shrink-0 rounded-full"
                                                            :class="colors[i % colors.length]"
                                                        /><span
                                                            >{{ r.symbol ? `${r.symbol} · ` : '' }}{{ r.name
                                                            }}<span
                                                                v-if="r.cost"
                                                                class="block text-xs"
                                                                :class="r.value >= r.cost ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'"
                                                                >{{ signed0(r.value - r.cost) }} on {{ m0(r.cost) }} paid</span
                                                            ><span class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                                                                ><select
                                                                    class="h-7 rounded border bg-background px-1.5 py-0 text-xs"
                                                                    :aria-label="`What ${r.name} invests in`"
                                                                    :value="r.asset_class"
                                                                    @change="setClass(r.key, ($event.target as HTMLSelectElement).value)"
                                                                >
                                                                    <option v-for="(l, k) in assetClasses" :key="k" :value="k">
                                                                        {{ l }}
                                                                    </option></select
                                                                ><template v-if="r.asset_class === 'mixed'"
                                                                    ><input
                                                                        class="h-7 w-12 rounded border bg-background px-1.5 text-xs"
                                                                        inputmode="numeric"
                                                                        :aria-label="`Percent of ${r.name} in stocks`"
                                                                        :value="r.stock_share"
                                                                        @change="
                                                                            setClass(
                                                                                r.key,
                                                                                'mixed',
                                                                                Math.round(toNum(($event.target as HTMLInputElement).value)),
                                                                            )
                                                                        "
                                                                    />% stocks</template
                                                                ></span
                                                            ></span
                                                        ></span
                                                    >
                                                </td>
                                                <td class="hidden px-3 py-1.5 text-right tabular-nums sm:table-cell">
                                                    {{ r.quantity?.toFixed(3) ?? '' }}
                                                </td>
                                                <td class="hidden px-3 py-1.5 text-right tabular-nums sm:table-cell">
                                                    {{ r.price ? money(Math.round(r.price * 100)) : '' }}
                                                </td>
                                                <td class="px-3 py-1.5 text-right tabular-nums">{{ m0(r.value) }}</td>
                                                <td class="py-1.5 pl-3 text-right tabular-nums">{{ share(r.share) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                        <div class="flex flex-wrap gap-2">
                            <Button variant="outline" @click="importOpen = true"><FileUp class="size-4" /> Import positions file</Button>
                            <Button variant="outline" @click="typeHoldings"><Pencil class="size-4" /> Type in holdings</Button>
                        </div>
                    </template>
                </template>
            </template>
        </div>

        <InvestmentImportDialog v-model:open="importOpen" :accounts="accounts" :account-id="filters.account" :kind-labels="activityKinds" />

        <Dialog v-model:open="acctOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ acctEditing ? 'Edit account' : 'Add an investment account' }}</DialogTitle>
                    <DialogDescription>A 401(k), IRA, brokerage or other account whose value comes from statements.</DialogDescription>
                </DialogHeader>
                <form id="acct-form" class="grid gap-4" @submit.prevent="saveAccount">
                    <div class="grid gap-2">
                        <Label for="ia-name">Name</Label>
                        <Input id="ia-name" v-model="acctForm.name" required placeholder="John Hancock 401(k)" />
                        <InputError :message="acctForm.errors.name" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="ia-kind">Type</Label>
                            <NativeSelect id="ia-kind" v-model="acctForm.kind">
                                <option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-2">
                            <Label for="ia-inst">Held at</Label>
                            <Input id="ia-inst" v-model="acctForm.institution" placeholder="Fidelity" />
                        </div>
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="acctForm.started_empty" type="checkbox" class="mt-0.5 size-4" />
                        <span
                            >It’s a new account that started at $0<span class="block text-xs text-muted-foreground"
                                >Growth is then counted from your first deposit, without a starting statement.</span
                            ></span
                        >
                    </label>
                </form>
                <div
                    v-if="acctEditing && confirmDelete"
                    class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
                >
                    Delete {{ acctEditing.name }} with all its balances, activity and holdings?
                    <div class="mt-2 flex gap-2">
                        <Button variant="destructive" size="sm" @click="deleteAccount">Yes, delete</Button>
                        <Button variant="outline" size="sm" @click="confirmDelete = false">Keep it</Button>
                    </div>
                </div>
                <DialogFooter class="gap-2 sm:justify-between">
                    <Button v-if="acctEditing && !confirmDelete" type="button" variant="ghost" class="text-red-600" @click="confirmDelete = true"
                        >Delete</Button
                    >
                    <span v-else />
                    <Button type="submit" form="acct-form" :disabled="acctForm.processing">Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="balOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add a statement balance</DialogTitle>
                    <DialogDescription>The account’s total value on a date, from a statement or the website.</DialogDescription>
                </DialogHeader>
                <form id="bal-form" class="grid gap-4" @submit.prevent="saveBalance">
                    <div class="grid gap-2">
                        <Label for="ib-acct">Account</Label>
                        <NativeSelect id="ib-acct" v-model="balForm.account_id">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="ib-date">Date</Label>
                            <Input id="ib-date" v-model="balForm.date" type="date" :max="today()" required />
                            <InputError :message="balForm.errors.date" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ib-bal">Total balance</Label>
                            <Input id="ib-bal" v-model="balForm.balance" inputmode="decimal" placeholder="0.00" required />
                            <InputError :message="balForm.errors.balance" />
                        </div>
                    </div>
                    <div v-if="balIs401k" class="grid gap-2 sm:max-w-[calc(50%-0.5rem)]">
                        <Label for="ib-loan">Loan still owed (optional)</Label>
                        <Input id="ib-loan" v-model="balForm.loan_balance" inputmode="decimal" placeholder="0.00" />
                        <InputError :message="balForm.errors.loan_balance" />
                    </div>
                </form>
                <DialogFooter>
                    <Button type="submit" form="bal-form" :disabled="balForm.processing">Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="actOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Type in activity</DialogTitle>
                    <DialogDescription>For money you add or take out that isn’t in a file, like the first deposit into Fidelity.</DialogDescription>
                </DialogHeader>
                <form id="act-form" class="grid gap-4" @submit.prevent="saveActivity">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="ix-acct">Account</Label>
                            <NativeSelect id="ix-acct" v-model="actForm.account_id">
                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-2">
                            <Label for="ix-kind">What happened</Label>
                            <NativeSelect id="ix-kind" v-model="actForm.kind">
                                <option v-for="(k, key) in activityKinds" :key="key" :value="key">{{ k.label }}</option>
                            </NativeSelect>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="ix-date">Date</Label>
                            <Input id="ix-date" v-model="actForm.date" type="date" required />
                            <InputError :message="actForm.errors.date" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ix-amt">Amount</Label>
                            <Input id="ix-amt" v-model="actForm.amount" inputmode="decimal" placeholder="0.00" required />
                            <InputError :message="actForm.errors.amount" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="ix-desc">Note (optional)</Label>
                        <Input id="ix-desc" v-model="actForm.description" placeholder="From TFCU Checking" />
                    </div>
                </form>
                <DialogFooter>
                    <Button type="submit" form="act-form" :disabled="actForm.processing">Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="holdOpen">
            <DialogContent class="max-h-[90dvh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Type in holdings</DialogTitle>
                    <DialogDescription
                        >Each fund and what it’s worth, from a statement or the website. This also saves the total as a balance.</DialogDescription
                    >
                </DialogHeader>
                <div class="grid gap-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="ih-acct">Account</Label>
                            <NativeSelect id="ih-acct" v-model="holdForm.account_id">
                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-2">
                            <Label for="ih-date">As of</Label>
                            <Input id="ih-date" v-model="holdForm.as_of" type="date" :max="today()" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="ih-total">Total balance (optional)</Label>
                        <Input
                            id="ih-total"
                            v-model="holdForm.total"
                            inputmode="decimal"
                            placeholder="0.00"
                            class="sm:max-w-48"
                            @input="totalTyped"
                        />
                        <p class="text-xs text-muted-foreground">
                            With a total, you can type each fund’s percent and the value is worked out for you.
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <div v-for="(r, i) in holdForm.rows" :key="i" class="flex gap-2">
                            <Input v-model="r.name" placeholder="Fund name" class="min-w-0 flex-1" :aria-label="`Fund ${i + 1}`" />
                            <Input
                                v-model="r.pct"
                                inputmode="decimal"
                                placeholder="%"
                                class="w-16"
                                :aria-label="`Percent ${i + 1}`"
                                @input="pctTyped(r)"
                            />
                            <Input v-model="r.value" inputmode="decimal" placeholder="Value" class="w-28" :aria-label="`Value ${i + 1}`" />
                            <Button variant="ghost" size="icon" aria-label="Remove line" @click="holdForm.rows.splice(i, 1)"
                                ><Trash2 class="size-4"
                            /></Button>
                        </div>
                        <div class="flex items-center justify-between">
                            <Button variant="outline" size="sm" @click="holdForm.rows.push({ name: '', pct: '', value: '' })"
                                ><Plus class="size-4" /> Add a fund</Button
                            >
                            <span class="text-sm tabular-nums"
                                ><template v-if="pctSum">{{ Math.round(pctSum * 10) / 10 }}% · </template>Total
                                {{ money(Math.round(holdTotal * 100)) }}</span
                            >
                        </div>
                    </div>
                    <InputError :message="holdErr" />
                </div>
                <DialogFooter>
                    <Button @click="saveHoldings">Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
