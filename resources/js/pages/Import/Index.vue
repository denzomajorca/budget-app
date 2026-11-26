<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    categoryLabel,
    guessDateFormat,
    guessMapping,
    headerRowIndex,
    readFile,
    toRows,
    type Mapping,
    type ParsedRow,
    type Settings,
    type Table,
} from '@/lib/importer';
import { money, shortDate } from '@/lib/money';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, FileUp, Undo2 } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

interface Account {
    id: number;
    name: string;
    type_label: string;
    is_debt: boolean;
    balance: number;
    import_settings: Settings | null;
}
interface ImportInfo {
    id: number;
    file_name: string;
    account_id: number;
    account_name: string | null;
    rows_imported: number;
    rows_skipped: number;
    categories_created: number;
    first_date: string | null;
    last_date: string | null;
    created_at: string | null;
}

const props = defineProps<{
    accounts: Account[];
    imports: ImportInfo[];
    done: (ImportInfo & { account: Account }) | null;
}>();

const LAST_ACCOUNT_KEY = 'budget.import.account';
function rememberedAccount(): number | null {
    try {
        const id = Number(localStorage.getItem(LAST_ACCOUNT_KEY));
        return props.accounts.some((a) => a.id === id) ? id : null;
    } catch {
        return null;
    }
}

const step = ref<'file' | 'columns' | 'preview'>('file');
const accountId = ref<number | null>(rememberedAccount() ?? props.accounts[0]?.id ?? null);
const account = computed(() => props.accounts.find((a) => a.id === accountId.value) ?? null);
const fileName = ref('');
const fileError = ref('');
const reading = ref(false);
const dragging = ref(false);
const headers = ref<string[]>([]);
const body = ref<Table>([]);
const firstLine = ref(2);
const settings = reactive<Settings>({
    mapping: { date: -1, payee: -1, amount: -1, debit: -1, credit: -1, category: -1, memo: -1 },
    headers: [],
    dateFormat: 'MDY',
    sign: 'neg-out',
});
const usedSaved = ref(false);

async function pick(file: File | undefined) {
    if (!file) return;
    fileError.value = '';
    reading.value = true;
    try {
        const table = await readFile(file);
        if (table.length < 2) throw new Error('the file has no rows');
        const h = headerRowIndex(table);
        headers.value = table[h].map((c, i) => String(c ?? '').trim() || `Column ${i + 1}`);
        body.value = table.slice(h + 1);
        firstLine.value = h + 2;
        fileName.value = file.name;
        const saved = account.value?.import_settings;
        usedSaved.value = !!saved && JSON.stringify(saved.headers) === JSON.stringify(headers.value);
        if (saved && usedSaved.value) {
            Object.assign(settings, JSON.parse(JSON.stringify(saved)));
        } else {
            settings.mapping = guessMapping(headers.value);
            settings.headers = [...headers.value];
            settings.dateFormat = guessDateFormat(body.value.slice(0, 200).map((r) => r[settings.mapping.date] ?? null));
            settings.sign = account.value?.import_settings?.sign ?? 'neg-out';
        }
        step.value = 'columns';
    } catch (e) {
        fileError.value = `Could not read that file: ${(e as Error).message}.`;
    } finally {
        reading.value = false;
    }
}
function onDrop(e: DragEvent) {
    dragging.value = false;
    pick(e.dataTransfer?.files?.[0]);
}

const fields: [keyof Mapping, string][] = [
    ['date', 'Date'],
    ['payee', 'Payee or description'],
    ['amount', 'Amount (one column)'],
    ['debit', 'or Money out column'],
    ['credit', 'and Money in column'],
    ['category', 'Category (Main:Sub)'],
    ['memo', 'Memo'],
];
const sample = computed(() => body.value.slice(0, 4));
const cellText = (v: unknown) => (v instanceof Date ? v.toISOString().slice(0, 10) : String(v ?? ''));
const mappingError = computed(() => {
    const m = settings.mapping;
    if (m.date < 0) return 'Pick the Date column.';
    if (m.payee < 0) return 'Pick the Payee column.';
    if (m.amount < 0 && m.debit < 0 && m.credit < 0) return 'Pick the Amount column, or the Money out and Money in columns.';
    return '';
});

// Preview
const rows = ref<ParsedRow[]>([]);
const dup = ref<boolean[]>([]);
const newCategories = ref<string[]>([]);
const includeDuplicates = ref(false);
const checking = ref(false);
const previewError = ref('');
const good = computed(() => rows.value.map((r, i) => ({ r, dup: dup.value[i] ?? false })).filter((x) => !x.r.error));
const bad = computed(() => rows.value.filter((r) => r.error));
const dupCount = computed(() => good.value.filter((x) => x.dup).length);
const toImport = computed(() => good.value.filter((x) => includeDuplicates.value || !x.dup).map((x) => x.r));
const totalIn = computed(() => toImport.value.reduce((s, r) => s + Math.max(0, r.amount!), 0));
const totalOut = computed(() => toImport.value.reduce((s, r) => s + Math.min(0, r.amount!), 0));

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}
async function preview() {
    previewError.value = '';
    rows.value = toRows(body.value, firstLine.value, settings);
    dup.value = [];
    newCategories.value = [];
    const ok = rows.value.filter((r) => !r.error);
    step.value = 'preview';
    if (!ok.length || !accountId.value) return;
    checking.value = true;
    try {
        const res = await fetch(route('imports.preview'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                account_id: accountId.value,
                rows: ok.map((r) => ({ date: r.date, amount: r.amount, payee: r.payee, category: r.category || null })),
            }),
        });
        const json = await res.json();
        if (!res.ok) throw new Error(json.message ?? 'The server could not check this file.');
        let k = 0;
        dup.value = rows.value.map((r) => (r.error ? false : json.duplicates[k++]));
        newCategories.value = json.new_categories;
    } catch (e) {
        previewError.value = (e as Error).message;
    } finally {
        checking.value = false;
    }
}

const saving = ref(false);
function commit() {
    if (!accountId.value || !toImport.value.length) return;
    try {
        localStorage.setItem(LAST_ACCOUNT_KEY, String(accountId.value));
    } catch {
        /* not available */
    }
    saving.value = true;
    router.post(
        route('imports.store'),
        {
            account_id: accountId.value,
            file_name: fileName.value,
            skipped: rows.value.length - toImport.value.length,
            settings: JSON.parse(JSON.stringify(settings)),
            rows: toImport.value.map((r) => ({ date: r.date, amount: r.amount, payee: r.payee, category: r.category || null, memo: r.memo || null })),
        },
        {
            onSuccess: () => {
                step.value = 'file';
                rows.value = [];
                window.scrollTo(0, 0);
            },
            onError: (errors) => (previewError.value = Object.values(errors)[0] ?? 'The import did not go through.'),
            onFinish: () => (saving.value = false),
        },
    );
}
function restart() {
    step.value = 'file';
    rows.value = [];
    fileName.value = '';
    router.get(route('imports.index'));
}

const doneSummary = computed(() => {
    const d = props.done;
    if (!d) return '';
    let t = `From ${d.file_name}` + (d.first_date && d.last_date ? `, ${shortDate(d.first_date)} to ${shortDate(d.last_date)}.` : '.');
    if (d.rows_skipped) t += ` ${d.rows_skipped} rows were skipped.`;
    if (d.categories_created) t += ` ${d.categories_created} new ${d.categories_created === 1 ? 'category was' : 'categories were'} added.`;
    return t;
});

// After an import: match the bank's balance
const bal = useForm({ balance: '', as_of: props.done?.last_date ?? '' });
watch(
    () => props.done?.id,
    () => {
        bal.reset();
        bal.as_of = props.done?.last_date ?? '';
    },
);
function setBalance() {
    if (!props.done) return;
    bal.post(route('accounts.balance', props.done.account_id), { preserveScroll: true, onSuccess: () => bal.reset('balance') });
}

const undoId = ref<number | null>(null);
function undo(id: number) {
    router.delete(route('imports.destroy', id), { onFinish: () => (undoId.value = null) });
}
</script>

<template>
    <Head title="Import" />
    <AppLayout :breadcrumbs="[{ title: 'Import', href: '/import' }]">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">Import a bank file</h1>
                <p class="text-sm text-muted-foreground">CSV, XLS or XLSX. The file is read on your device; only the transactions are saved.</p>
            </div>

            <!-- Finished import -->
            <Card v-if="done && step === 'file'" class="border-emerald-600/40">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base"
                        ><CheckCircle2 class="size-5 text-emerald-600" />Imported {{ done.rows_imported }} transactions into
                        {{ done.account_name }}</CardTitle
                    >
                    <CardDescription>{{ doneSummary }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4">
                    <div class="flex flex-wrap gap-2">
                        <Button as-child variant="outline"
                            ><Link :href="route('transactions.index', { import: done.id })">See these transactions</Link></Button
                        >
                        <Button variant="outline" @click="restart">Import another file</Button>
                    </div>
                    <div class="rounded-lg border p-4">
                        <div class="text-sm font-medium">Make the balance match your bank</div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ done.account.name }} shows {{ money(done.account.balance) }} here. Type the
                            {{ done.account.is_debt ? 'amount owed' : 'balance' }} your bank shows for that date, and the app works out the starting
                            balance for you.
                        </p>
                        <form class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" @submit.prevent="setBalance">
                            <div class="grid gap-1.5">
                                <Label for="b-amt">{{ done.account.is_debt ? 'Amount owed' : 'Balance' }}</Label>
                                <Input id="b-amt" v-model="bal.balance" inputmode="decimal" placeholder="0.00" required />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="b-date">On this date</Label>
                                <Input id="b-date" v-model="bal.as_of" type="date" required />
                            </div>
                            <Button type="submit" :disabled="bal.processing">Set balance</Button>
                        </form>
                        <InputError class="mt-2" :message="bal.errors.balance || bal.errors.as_of" />
                    </div>
                </CardContent>
            </Card>

            <!-- Step 1: account and file -->
            <Card v-if="step === 'file'">
                <CardContent class="grid gap-4 p-6">
                    <p v-if="accounts.length === 0" class="text-sm">
                        Add an account first on the <Link :href="route('accounts.index')" class="underline">Accounts page</Link>.
                    </p>
                    <template v-else>
                        <div class="grid max-w-sm gap-2">
                            <Label for="i-acct">Import into</Label>
                            <NativeSelect id="i-acct" v-model="accountId">
                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </NativeSelect>
                        </div>
                        <label
                            class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed p-8 text-center transition-colors"
                            :class="dragging ? 'border-primary bg-muted' : 'border-input hover:bg-muted/50'"
                            @dragover.prevent="dragging = true"
                            @dragleave="dragging = false"
                            @drop.prevent="onDrop"
                        >
                            <FileUp class="size-8 text-muted-foreground" />
                            <span class="font-medium">{{ reading ? 'Reading…' : 'Choose a file or drop it here' }}</span>
                            <span class="text-sm text-muted-foreground">CSV, XLS or XLSX from your bank</span>
                            <input
                                type="file"
                                accept=".csv,.txt,.xls,.xlsx,text/csv"
                                class="sr-only"
                                @change="pick(($event.target as HTMLInputElement).files?.[0])"
                            />
                        </label>
                        <p v-if="fileError" class="text-sm text-red-600">{{ fileError }}</p>
                        <p class="text-sm text-muted-foreground">
                            Files with Date, Payee, Amount, Category and Memo columns are recognized automatically. Categories written as Main:Sub,
                            like Food and Dining:Groceries, are split into a category and subcategory, and any that don't exist yet are added.
                        </p>
                    </template>
                </CardContent>
            </Card>

            <!-- Step 2: columns -->
            <Card v-if="step === 'columns'">
                <CardHeader>
                    <CardTitle class="text-base">Check the columns in {{ fileName }}</CardTitle>
                    <CardDescription>
                        {{ body.length }} rows found for {{ account?.name }}.
                        {{
                            usedSaved
                                ? 'Using the same settings as your last import into this account.'
                                : 'We guessed from the headers; fix anything that looks wrong.'
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-5">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div v-for="[key, label] in fields" :key="key" class="grid gap-1.5">
                            <Label :for="'m-' + key">{{ label }}</Label>
                            <NativeSelect :id="'m-' + key" v-model="settings.mapping[key]">
                                <option :value="-1">Not in file</option>
                                <option v-for="(h, i) in headers" :key="i" :value="i">{{ h }}</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="m-df">Dates are written</Label>
                            <NativeSelect id="m-df" v-model="settings.dateFormat">
                                <option value="MDY">Month/Day/Year (10/16/25)</option>
                                <option value="DMY">Day/Month/Year (16/10/25)</option>
                                <option value="YMD">Year-Month-Day (2025-10-16)</option>
                            </NativeSelect>
                        </div>
                        <div v-if="settings.mapping.amount >= 0" class="grid gap-1.5">
                            <Label for="m-sign">Money out shows as</Label>
                            <NativeSelect id="m-sign" v-model="settings.sign">
                                <option value="neg-out">Negative (−45.10)</option>
                                <option value="pos-out">Positive (45.10)</option>
                            </NativeSelect>
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th v-for="(h, i) in headers" :key="i" class="whitespace-nowrap px-3 py-2 font-medium">{{ h }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="(r, ri) in sample" :key="ri">
                                    <td v-for="(h, i) in headers" :key="i" class="whitespace-nowrap px-3 py-2">{{ cellText(r[i]) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="mappingError" class="text-sm text-red-600">{{ mappingError }}</p>
                    <div class="flex gap-2">
                        <Button variant="outline" @click="step = 'file'">Back</Button>
                        <Button :disabled="!!mappingError" @click="preview">Preview</Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Step 3: preview -->
            <Card v-if="step === 'preview'">
                <CardHeader>
                    <CardTitle class="text-base">Ready to import into {{ account?.name }}</CardTitle>
                    <CardDescription>
                        <template v-if="checking">Checking for transactions you already have…</template>
                        <template v-else>
                            {{ toImport.length }} transactions will be added: in {{ money(totalIn) }}, out {{ money(-totalOut) }}.
                        </template>
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4">
                    <ul class="grid gap-2 text-sm">
                        <li v-if="dupCount" class="rounded-md bg-amber-50 p-3 text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                            {{ dupCount }} {{ dupCount === 1 ? 'row is' : 'rows are' }} already in this account (same date, amount and payee) and will
                            be skipped.
                            <label class="mt-2 flex items-center gap-2"
                                ><input v-model="includeDuplicates" type="checkbox" class="size-4" /> Import them anyway</label
                            >
                        </li>
                        <li v-if="bad.length" class="rounded-md bg-red-50 p-3 text-red-900 dark:bg-red-950 dark:text-red-200">
                            {{ bad.length }} {{ bad.length === 1 ? 'row' : 'rows' }} can't be read and will be skipped (first one: line
                            {{ bad[0].line }}, {{ bad[0].error?.toLowerCase() }}). If many rows show this, go back and check the columns and date
                            format.
                        </li>
                        <li v-if="newCategories.length" class="rounded-md bg-muted p-3">
                            {{ newCategories.length }} new {{ newCategories.length === 1 ? 'category' : 'categories' }} will be added:
                            {{ newCategories.slice(0, 12).join(', ')
                            }}<template v-if="newCategories.length > 12">, and {{ newCategories.length - 12 }} more</template>.
                        </li>
                    </ul>
                    <p v-if="previewError" class="text-sm text-red-600">{{ previewError }}</p>

                    <div class="max-h-[28rem] overflow-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-muted text-left text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Date</th>
                                    <th class="px-3 py-2 font-medium">Payee</th>
                                    <th class="hidden px-3 py-2 font-medium sm:table-cell">Category</th>
                                    <th class="px-3 py-2 text-right font-medium">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="(r, i) in rows.slice(0, 300)"
                                    :key="i"
                                    :class="
                                        r.error || (dup[i] && !includeDuplicates)
                                            ? 'text-muted-foreground line-through decoration-muted-foreground/50'
                                            : ''
                                    "
                                >
                                    <td class="whitespace-nowrap px-3 py-2">{{ r.date ? shortDate(r.date) : '?' }}</td>
                                    <td class="px-3 py-2">
                                        {{ r.payee || '?' }}
                                        <span v-if="r.error" class="ml-1 text-xs text-red-600 no-underline">{{ r.error }}</span>
                                        <span v-else-if="dup[i]" class="ml-1 text-xs text-amber-700 no-underline dark:text-amber-400"
                                            >already there</span
                                        >
                                        <div class="text-xs text-muted-foreground sm:hidden">{{ categoryLabel(r.category) || 'Uncategorized' }}</div>
                                    </td>
                                    <td class="hidden px-3 py-2 sm:table-cell">{{ categoryLabel(r.category) || 'Uncategorized' }}</td>
                                    <td
                                        class="whitespace-nowrap px-3 py-2 text-right tabular-nums"
                                        :class="(r.amount ?? 0) > 0 ? 'text-emerald-700 dark:text-emerald-400' : ''"
                                    >
                                        {{ r.amount === null ? '?' : money(r.amount) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="rows.length > 300" class="text-xs text-muted-foreground">Showing the first 300 of {{ rows.length }} rows.</p>
                    <div class="flex gap-2">
                        <Button variant="outline" @click="step = 'columns'">Back</Button>
                        <Button :disabled="checking || saving || !toImport.length" @click="commit">
                            {{ saving ? 'Importing…' : `Import ${toImport.length} transactions` }}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- History -->
            <Card v-if="imports.length && step === 'file'">
                <CardHeader class="pb-2"><CardTitle class="text-base">Recent imports</CardTitle></CardHeader>
                <CardContent class="p-0">
                    <ul class="divide-y">
                        <li v-for="i in imports" :key="i.id" class="flex flex-wrap items-center gap-3 px-6 py-3 text-sm">
                            <div class="min-w-0 flex-1">
                                <Link :href="route('transactions.index', { import: i.id })" class="font-medium hover:underline">{{
                                    i.file_name
                                }}</Link>
                                <div class="text-muted-foreground">
                                    {{ i.rows_imported }} into {{ i.account_name
                                    }}<template v-if="i.first_date && i.last_date">
                                        · {{ shortDate(i.first_date) }} to {{ shortDate(i.last_date) }}</template
                                    >
                                </div>
                            </div>
                            <template v-if="undoId === i.id">
                                <span>Remove these {{ i.rows_imported }} transactions?</span>
                                <Button size="sm" variant="destructive" @click="undo(i.id)">Yes, undo</Button>
                                <Button size="sm" variant="outline" @click="undoId = null">Keep</Button>
                            </template>
                            <Button v-else size="sm" variant="ghost" @click="undoId = i.id"><Undo2 class="size-4" />Undo</Button>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
