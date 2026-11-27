<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { readFile } from '@/lib/importer';
import { parseInvestmentFile, type Parsed } from '@/lib/investments';
import { money, shortDate, today } from '@/lib/money';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    accounts: { id: number; name: string; kind: string; institution: string | null }[];
    accountId: number | null;
    kindLabels: Record<string, { label: string; flow: string }>;
}>();
const open = defineModel<boolean>('open', { required: true });

const parsed = ref<Parsed | null>(null);
const fileName = ref('');
const err = ref('');
const account = ref<number | null>(null);
const asOf = ref(today());
const fileAccount = ref('');
const saving = ref(false);
const serverErr = ref('');

watch(open, (o) => {
    if (!o) return;
    parsed.value = null;
    fileName.value = '';
    err.value = '';
    serverErr.value = '';
    account.value = props.accountId ?? props.accounts[0]?.id ?? null;
});

async function pick(e: Event) {
    const f = (e.target as HTMLInputElement).files?.[0];
    if (!f) return;
    err.value = '';
    parsed.value = null;
    try {
        const p = parseInvestmentFile(await readFile(f));
        parsed.value = p;
        fileName.value = f.name;
        if (p.type === 'holdings') {
            asOf.value = p.asOf && p.asOf <= today() ? p.asOf : today();
            fileAccount.value = p.accounts[0] ?? '';
        }
        guessAccount(p);
    } catch (x) {
        err.value = 'Could not read that file. ' + (x instanceof Error ? x.message : '');
    }
}

/** Pick the account that matches the file's institution, when there is one. */
function guessAccount(p: Parsed) {
    if (props.accountId) return;
    const want = p.source === 'Fidelity' ? /fidelity/i : p.source === 'John Hancock' ? /hancock|401/i : null;
    const hit = want && props.accounts.find((a) => want.test(`${a.institution ?? ''} ${a.name} ${a.kind}`));
    if (hit) account.value = hit.id;
}

const holdingRows = computed(() => {
    const p = parsed.value;
    if (!p || p.type !== 'holdings') return [];
    return p.accounts.length > 1 ? p.rows.filter((r) => r.account === fileAccount.value) : p.rows;
});
const holdingsTotal = computed(() => holdingRows.value.reduce((s, r) => s + r.value, 0));

const summary = computed(() => {
    const p = parsed.value;
    if (!p || p.type !== 'activity') return null;
    const by: Record<string, { n: number; total: number }> = {};
    for (const r of p.rows) {
        const flow = props.kindLabels[r.kind]?.flow;
        const amt = flow === 'in' ? Math.abs(r.amount) : flow === 'out' ? -Math.abs(r.amount) : r.amount;
        by[r.kind] ??= { n: 0, total: 0 };
        by[r.kind].n++;
        by[r.kind].total += amt;
    }
    const dates = p.rows.map((r) => r.date).sort();
    return { by, from: dates[0], to: dates[dates.length - 1] };
});

function save() {
    const p = parsed.value;
    if (!p || !account.value) return;
    saving.value = true;
    serverErr.value = '';
    const payload =
        p.type === 'holdings'
            ? { account_id: account.value, file_name: fileName.value, type: 'holdings', as_of: asOf.value, rows: holdingRows.value }
            : { account_id: account.value, file_name: fileName.value, type: p.type, rows: p.rows };
    router.post(route('investments.import'), payload as never, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
        onError: (e) => (serverErr.value = Object.values(e)[0] ?? 'Something in the file could not be saved.'),
        onFinish: () => (saving.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Import an investment file</DialogTitle>
                <DialogDescription
                    >The John Hancock activity file, Fidelity’s Activity &amp; Orders (history) or Positions download, as CSV or Excel. It’s read on
                    your device; only the rows are saved.</DialogDescription
                >
            </DialogHeader>
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="inv-file">File</Label>
                    <input
                        id="inv-file"
                        type="file"
                        accept=".csv,.txt,.xls,.xlsx,text/csv"
                        class="text-sm file:mr-3 file:rounded-md file:border file:bg-background file:px-3 file:py-1.5 file:text-sm"
                        @change="pick"
                    />
                    <p v-if="err" class="text-sm text-red-600">{{ err }}</p>
                </div>

                <template v-if="parsed">
                    <div class="grid gap-2">
                        <Label for="inv-acct">Into which account</Label>
                        <NativeSelect id="inv-acct" v-model="account">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </NativeSelect>
                    </div>

                    <div v-if="summary && parsed.type === 'activity'" class="rounded-md border p-3 text-sm">
                        <p class="mb-2">
                            <b>{{ parsed.rows.length }}</b> rows from {{ parsed.source === 'file' ? 'the file' : parsed.source }},
                            {{ shortDate(summary.from) }} to {{ shortDate(summary.to) }}.
                            <span v-if="parsed.skipped" class="text-muted-foreground"
                                >{{ parsed.skipped }} lines without a date or amount were left out.</span
                            >
                        </p>
                        <table class="w-full">
                            <tbody>
                                <tr v-for="(v, k) in summary.by" :key="k" class="border-t">
                                    <td class="py-1">{{ kindLabels[k]?.label ?? k }}</td>
                                    <td class="py-1 text-right text-muted-foreground">{{ v.n }}</td>
                                    <td class="py-1 text-right tabular-nums">{{ money(v.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="mt-2 text-xs text-muted-foreground">
                            Rows already in the app are skipped, so it’s safe to import a newer file that overlaps.
                        </p>
                    </div>

                    <div v-if="parsed.type === 'balances'" class="grid gap-2 rounded-md border p-3 text-sm">
                        <p>
                            <b>{{ parsed.rows.length }}</b> statement balances, {{ shortDate(parsed.rows[0].date) }} to
                            {{ shortDate(parsed.rows[parsed.rows.length - 1].date) }}. A balance already saved for the same date is replaced.
                        </p>
                        <ul class="grid grid-cols-1 gap-1">
                            <li v-for="r in parsed.rows.slice(-8)" :key="r.date" class="flex justify-between gap-3 border-t pt-1">
                                <span class="min-w-0 truncate"
                                    >{{ shortDate(r.date) }}<span v-if="r.loan" class="text-muted-foreground"> · loan {{ money(r.loan) }}</span
                                    ><span v-if="r.money_in" class="text-muted-foreground"> · in {{ money(r.money_in) }}</span></span
                                >
                                <span class="tabular-nums">{{ money(r.balance) }}</span>
                            </li>
                            <li v-if="parsed.rows.length > 8" class="text-muted-foreground">and {{ parsed.rows.length - 8 }} earlier</li>
                        </ul>
                        <p class="text-xs text-muted-foreground">
                            Money put in is only added for periods your activity files don’t already cover, so nothing is counted twice.
                        </p>
                    </div>
                    <div v-if="parsed.type === 'holdings'" class="grid gap-3 rounded-md border p-3 text-sm">
                        <div v-if="parsed.accounts.length > 1" class="grid gap-2">
                            <Label for="inv-facct">This file has several accounts. Which one is this?</Label>
                            <NativeSelect id="inv-facct" v-model="fileAccount">
                                <option v-for="a in parsed.accounts" :key="a" :value="a">{{ a }}</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-2 sm:max-w-48">
                            <Label for="inv-asof">Holdings as of</Label>
                            <Input id="inv-asof" v-model="asOf" type="date" :max="today()" />
                        </div>
                        <p>
                            <b>{{ holdingRows.length }}</b> holdings worth <b>{{ money(holdingsTotal) }}</b
                            >. This also saves that total as the account’s balance on that date.
                        </p>
                        <ul class="grid grid-cols-1 gap-1">
                            <li v-for="(r, i) in holdingRows.slice(0, 8)" :key="i" class="flex justify-between gap-3 border-t pt-1">
                                <span class="min-w-0 truncate">{{ r.symbol ? r.symbol + ' · ' : '' }}{{ r.name }}</span>
                                <span class="tabular-nums">{{ money(r.value) }}</span>
                            </li>
                            <li v-if="holdingRows.length > 8" class="text-muted-foreground">and {{ holdingRows.length - 8 }} more</li>
                        </ul>
                    </div>
                    <InputError :message="serverErr" />
                </template>
            </div>
            <DialogFooter>
                <Button :disabled="!parsed || !account || saving" @click="save">Import</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
