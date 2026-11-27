<script setup lang="ts">
import NativeSelect from '@/components/NativeSelect.vue';
import TransactionDialog, { type CategoryOption, type Txn } from '@/components/TransactionDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { groupByKind } from '@/lib/categories';
import { money, shortDate } from '@/lib/money';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { CheckCheck, Plus, Wand2, X } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

interface Row extends Txn {
    account: string | null;
    category: string | null;
    is_transfer: boolean;
    transfer: string | null;
    needs_review: boolean;
}
interface Filters {
    account: string | number | null;
    category: string | null;
    from: string | null;
    to: string | null;
    q: string | null;
    import: string | number | null;
    transfers: string | null;
    review: string | null;
}

const props = defineProps<{
    transactions: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    totals: { in: number; out: number; count: number };
    filters: Filters;
    accounts: { id: number; name: string; is_archived: boolean }[];
    categories: CategoryOption[];
    unpaired_transfers: number;
    to_review: number;
    uncategorized: number;
    rules: { id: number; match: string; category: string | null }[];
}>();

const f = reactive({
    account: props.filters.account ? String(props.filters.account) : '',
    category: props.filters.category ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    q: props.filters.q ?? '',
    import: props.filters.import ? String(props.filters.import) : '',
    transfers: props.filters.transfers ?? '',
    review: props.filters.review ?? '',
});
function categoryText(t: Row) {
    if (t.transfer) return 'Transfer: ' + t.transfer;
    if (t.is_transfer) return (t.category ?? 'Transfer') + ' (no match found)';
    return t.category ?? 'Uncategorized';
}
const onlyMain = computed(() => {
    if (!f.category.startsWith('only-')) return '';
    const id = Number(f.category.slice(5));
    return props.categories.find((c) => c.id === id)?.label ?? 'Category';
});
const hasFilters = computed(() => Object.values(f).some((v) => v !== ''));

function apply() {
    const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''));
    router.get(route('transactions.index'), params, { preserveState: true, preserveScroll: true, replace: true });
}
const applySearch = useDebounceFn(apply, 350);
function clear() {
    Object.assign(f, { account: '', category: '', from: '', to: '', q: '', import: '', transfers: '', review: '' });
    apply();
}

const dialogOpen = ref(false);
const editing = ref<Txn | null>(null);
function add() {
    editing.value = null;
    dialogOpen.value = true;
}
function edit(t: Row) {
    editing.value = t;
    dialogOpen.value = true;
}
// Review: tick rows, set a category on many at once, mark them reviewed.
const selected = ref<number[]>([]);
const allTicked = computed(() => props.transactions.data.length > 0 && props.transactions.data.every((t) => selected.value.includes(t.id)));
function toggleAll() {
    selected.value = allTicked.value ? [] : props.transactions.data.map((t) => t.id);
}
const bulkCategory = ref('');
const busy = ref(false);
function bulk(payload: Record<string, unknown>, after?: () => void) {
    busy.value = true;
    router.post(route('transactions.bulk'), payload, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
            after?.();
        },
        onFinish: () => (busy.value = false),
    });
}
function setCategoryOnTicked() {
    if (!selected.value.length || bulkCategory.value === '') return;
    const rows = props.transactions.data.filter((t) => selected.value.includes(t.id));
    const categoryId = bulkCategory.value === 'none' ? null : Number(bulkCategory.value);
    bulk({ ids: selected.value, action: 'category', category_id: categoryId }, () => {
        bulkCategory.value = '';
        // All the same payee? Offer to remember it.
        const payees = new Set(rows.map((t) => suggestMatch(t.payee)));
        if (categoryId && payees.size === 1) offerRule(rows[0].payee, categoryId);
    });
}
function markTickedReviewed() {
    if (selected.value.length) bulk({ ids: selected.value, action: 'reviewed' });
}
function markAllShownReviewed() {
    bulk({ all: true, filters: { ...f }, action: 'reviewed' });
}
function quickSet(t: Row, value: string) {
    const categoryId = value === '' ? null : Number(value);
    bulk({ ids: [t.id], action: 'category', category_id: categoryId }, () => {
        if (categoryId) offerRule(t.payee, categoryId);
    });
}
function toggleFilter(key: 'review' | 'uncategorized') {
    if (key === 'review') f.review = f.review ? '' : '1';
    else f.category = f.category === 'none' ? '' : 'none';
    selected.value = [];
    apply();
}

// "Always file payees containing ... under ..." — offered after a fix.
/** A payee without the store numbers and dates banks add: "PUBLIX #1234 TAMPA" → "publix". */
function suggestMatch(payee: string) {
    const cut = payee
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .split(/\s*(?:#|\*|\d{3,}|\s{2})/)[0]
        .trim();
    return cut.length >= 3 ? cut : payee.toLowerCase().trim();
}
const ruleOffer = ref<{ payee: string; category: string } | null>(null);
const ruleForm = useForm({ match: '', category_id: null as number | null });
function offerRule(payee: string, categoryId: number) {
    if (props.rules.some((r) => payee.toLowerCase().includes(r.match))) return; // already covered
    ruleForm.match = suggestMatch(payee);
    ruleForm.category_id = categoryId;
    ruleForm.clearErrors();
    ruleOffer.value = { payee, category: props.categories.find((c) => c.id === categoryId)?.label ?? '' };
}
function saveRule() {
    ruleForm.post(route('payee-rules.store'), { preserveScroll: true, onSuccess: () => (ruleOffer.value = null) });
}
function deleteRule(id: number) {
    router.delete(route('payee-rules.destroy', id), { preserveScroll: true });
}
const showRules = ref(false);

const pageLinks = computed(() => props.transactions.links.map((l) => ({ ...l, label: l.label.replace('&laquo;', '‹').replace('&raquo;', '›') })));
</script>

<template>
    <Head title="Transactions" />
    <AppLayout :breadcrumbs="[{ title: 'Transactions', href: '/transactions' }]">
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-semibold">Transactions</h1>
                <Button @click="add"><Plus class="size-4" />Add transaction</Button>
            </div>

            <Card>
                <CardContent class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1.3fr_1.6fr_1fr_1fr_auto]">
                    <Input v-model="f.q" type="search" placeholder="Search payee or memo" aria-label="Search" @input="applySearch" />
                    <NativeSelect v-model="f.account" aria-label="Account" @change="apply">
                        <option value="">All accounts</option>
                        <option v-for="a in accounts" :key="a.id" :value="String(a.id)">{{ a.name }}</option>
                    </NativeSelect>
                    <NativeSelect v-model="f.category" aria-label="Category" @change="apply">
                        <option value="">All categories</option>
                        <option value="none">Uncategorized</option>
                        <option v-if="onlyMain" :value="f.category">{{ onlyMain }} (without subcategories)</option>
                        <optgroup v-for="g in groupByKind(categories)" :key="g.kind" :label="g.title">
                            <option v-for="c in g.options" :key="c.id" :value="String(c.id)">{{ c.parent_id ? ' ' : '' }}{{ c.label }}</option>
                        </optgroup>
                    </NativeSelect>
                    <label class="relative block">
                        <span class="pointer-events-none absolute -top-2 left-2 bg-card px-1 text-xs text-muted-foreground">From</span>
                        <Input v-model="f.from" type="date" @change="apply" />
                    </label>
                    <label class="relative block">
                        <span class="pointer-events-none absolute -top-2 left-2 bg-card px-1 text-xs text-muted-foreground">To</span>
                        <Input v-model="f.to" type="date" @change="apply" />
                    </label>
                    <Button v-if="hasFilters" variant="ghost" @click="clear"><X class="size-4" />Clear</Button>
                </CardContent>
            </Card>

            <div class="flex flex-wrap gap-2">
                <Button size="sm" :variant="f.review ? 'default' : 'outline'" @click="toggleFilter('review')">
                    Needs review <span class="tabular-nums">({{ to_review }})</span>
                </Button>
                <Button size="sm" :variant="f.category === 'none' ? 'default' : 'outline'" @click="toggleFilter('uncategorized')">
                    Uncategorized <span class="tabular-nums">({{ uncategorized }})</span>
                </Button>
                <Button v-if="f.review && transactions.total" size="sm" variant="ghost" :disabled="busy" @click="markAllShownReviewed">
                    <CheckCheck class="size-4" />Mark all {{ transactions.total }} shown as reviewed
                </Button>
            </div>

            <Card v-if="ruleOffer" class="border-primary/40">
                <CardContent class="grid gap-3 p-4">
                    <div class="flex items-start gap-2 text-sm">
                        <Wand2 class="mt-0.5 size-4 shrink-0" />
                        <div>
                            Remember this for future imports? Any payee containing the words below goes to
                            <span class="font-medium">{{ ruleOffer.category }}</span> automatically.
                        </div>
                    </div>
                    <form class="flex flex-wrap items-center gap-2" @submit.prevent="saveRule">
                        <Input v-model="ruleForm.match" class="max-w-xs" aria-label="Payee contains" />
                        <Button type="submit" size="sm" :disabled="ruleForm.processing">Remember</Button>
                        <Button type="button" size="sm" variant="ghost" @click="ruleOffer = null">No thanks</Button>
                    </form>
                    <p v-if="ruleForm.errors.match" class="text-sm text-red-600">{{ ruleForm.errors.match }}</p>
                    <p class="text-xs text-muted-foreground">From "{{ ruleOffer.payee }}". Shorten it to match more, like just "publix".</p>
                </CardContent>
            </Card>

            <p v-if="f.transfers" class="text-sm">
                Showing transfers with no matching other side. Usually the other account isn't imported yet, or one side is in a spending category.
                Fix it by giving both sides a Transfer category.
                <button type="button" class="underline" @click="((f.transfers = ''), apply())">Show all</button>
            </p>
            <p v-else-if="unpaired_transfers" class="text-sm text-muted-foreground">
                {{ unpaired_transfers }} {{ unpaired_transfers === 1 ? 'transfer has' : 'transfers have' }} no matching other side.
                <button type="button" class="underline" @click="((f.transfers = 'unpaired'), apply())">Review them</button>
            </p>
            <p v-if="f.import" class="text-sm">
                Showing one import only.
                <button type="button" class="underline" @click="((f.import = ''), apply())">Show all</button>
            </p>
            <p class="text-sm text-muted-foreground">
                {{ totals.count }} {{ totals.count === 1 ? 'transaction' : 'transactions'
                }}<template v-if="totals.count">
                    · in <span class="tabular-nums text-foreground">{{ money(totals.in) }}</span> · out
                    <span class="tabular-nums text-foreground">{{ money(-totals.out) }}</span></template
                >
            </p>

            <Card v-if="transactions.data.length === 0">
                <CardContent class="p-6 text-sm text-muted-foreground">
                    <template v-if="hasFilters">Nothing matches these filters.</template>
                    <template v-else-if="accounts.length === 0">
                        Start on the <Link :href="route('accounts.index')" class="underline">Accounts page</Link> by adding an account. Then add
                        transactions here.
                    </template>
                    <template v-else>No transactions yet. Add one, or wait for step 3 to import your bank files.</template>
                </CardContent>
            </Card>

            <template v-else>
                <div v-if="selected.length" class="sticky top-2 z-10 flex flex-wrap items-center gap-2 rounded-lg border bg-background p-3 shadow-sm">
                    <span class="text-sm font-medium">{{ selected.length }} ticked</span>
                    <NativeSelect v-model="bulkCategory" class="h-9 w-auto max-w-[16rem] py-0" aria-label="Category for ticked rows">
                        <option value="" disabled>Set category…</option>
                        <option value="none">Uncategorized</option>
                        <optgroup v-for="g in groupByKind(categories)" :key="g.kind" :label="g.title">
                            <option v-for="c in g.options" :key="c.id" :value="String(c.id)">{{ c.parent_id ? ' ' : '' }}{{ c.label }}</option>
                        </optgroup>
                    </NativeSelect>
                    <Button size="sm" :disabled="busy || bulkCategory === ''" @click="setCategoryOnTicked">Apply</Button>
                    <Button size="sm" variant="outline" :disabled="busy" @click="markTickedReviewed"
                        ><CheckCheck class="size-4" />Mark reviewed</Button
                    >
                    <Button size="sm" variant="ghost" @click="selected = []">Clear</Button>
                </div>
                <!-- Phones: a simple list -->
                <Card class="md:hidden">
                    <ul class="divide-y">
                        <li
                            v-for="t in transactions.data"
                            :key="t.id"
                            class="flex items-start"
                            :class="t.needs_review ? 'bg-amber-50/60 dark:bg-amber-950/20' : ''"
                        >
                            <input
                                v-model="selected"
                                type="checkbox"
                                :value="t.id"
                                class="ml-4 mt-4 size-4 shrink-0 rounded border-input"
                                :aria-label="'Tick ' + t.payee"
                            />
                            <div class="w-0 min-w-0 flex-1">
                                <button type="button" class="flex w-full items-start gap-3 px-3 py-3 pb-2 text-left" @click="edit(t)">
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate font-medium">
                                            {{ t.payee }}
                                            <span
                                                v-if="t.needs_review"
                                                class="ml-1 whitespace-nowrap rounded bg-amber-100 px-1.5 py-0.5 text-xs font-normal text-amber-900 dark:bg-amber-900/40 dark:text-amber-200"
                                                >To review</span
                                            >
                                        </div>
                                        <div class="truncate text-sm text-muted-foreground">{{ categoryText(t) }}</div>
                                        <div class="truncate text-xs text-muted-foreground">{{ shortDate(t.date) }} · {{ t.account }}</div>
                                    </div>
                                    <div class="font-medium tabular-nums" :class="t.amount > 0 ? 'text-emerald-700 dark:text-emerald-400' : ''">
                                        {{ money(t.amount) }}
                                    </div>
                                </button>
                                <div v-if="(t.needs_review || !t.category_id) && !t.transfer" class="px-3 pb-3">
                                    <NativeSelect
                                        class="h-9 py-0"
                                        :model-value="t.category_id ? String(t.category_id) : ''"
                                        :aria-label="'Category for ' + t.payee"
                                        @change="quickSet(t, ($event.target as HTMLSelectElement).value)"
                                    >
                                        <option value="">Uncategorized</option>
                                        <optgroup v-for="g in groupByKind(categories)" :key="g.kind" :label="g.title">
                                            <option v-for="c in g.options" :key="c.id" :value="String(c.id)">
                                                {{ c.parent_id ? ' ' : '' }}{{ c.label }}
                                            </option>
                                        </optgroup>
                                    </NativeSelect>
                                </div>
                            </div>
                        </li>
                    </ul>
                </Card>
                <!-- Larger screens: a table -->
                <Card class="hidden overflow-hidden md:block">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="w-10 py-2 pl-4">
                                    <input
                                        type="checkbox"
                                        :checked="allTicked"
                                        class="size-4 rounded border-input"
                                        aria-label="Tick all on this page"
                                        @change="toggleAll"
                                    />
                                </th>
                                <th class="px-4 py-2 font-medium">Date</th>
                                <th class="px-4 py-2 font-medium">Payee</th>
                                <th class="px-4 py-2 font-medium">Category</th>
                                <th class="px-4 py-2 font-medium">Account</th>
                                <th class="px-4 py-2 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="t in transactions.data"
                                :key="t.id"
                                class="cursor-pointer hover:bg-muted/40"
                                :class="t.needs_review ? 'bg-amber-50/60 dark:bg-amber-950/20' : ''"
                                tabindex="0"
                                @click="edit(t)"
                                @keydown.enter="edit(t)"
                            >
                                <td class="py-2.5 pl-4" @click.stop>
                                    <input
                                        v-model="selected"
                                        type="checkbox"
                                        :value="t.id"
                                        class="size-4 rounded border-input"
                                        :aria-label="'Tick ' + t.payee"
                                    />
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5">{{ shortDate(t.date) }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="font-medium">
                                        {{ t.payee }}
                                        <span
                                            v-if="t.needs_review"
                                            class="ml-1 whitespace-nowrap rounded bg-amber-100 px-1.5 py-0.5 text-xs font-normal text-amber-900 dark:bg-amber-900/40 dark:text-amber-200"
                                            >To review</span
                                        >
                                    </div>
                                    <div v-if="t.memo" class="text-xs text-muted-foreground">{{ t.memo }}</div>
                                </td>
                                <td class="px-4 py-2" @click.stop>
                                    <div v-if="t.transfer" class="text-muted-foreground" @click="edit(t)">{{ categoryText(t) }}</div>
                                    <NativeSelect
                                        v-else
                                        class="h-8 border-transparent bg-transparent px-2 py-0 hover:border-input"
                                        :class="t.category_id ? '' : 'text-muted-foreground'"
                                        :model-value="t.category_id ? String(t.category_id) : ''"
                                        :aria-label="'Category for ' + t.payee"
                                        @change="quickSet(t, ($event.target as HTMLSelectElement).value)"
                                    >
                                        <option value="">Uncategorized</option>
                                        <optgroup v-for="g in groupByKind(categories)" :key="g.kind" :label="g.title">
                                            <option v-for="c in g.options" :key="c.id" :value="String(c.id)">
                                                {{ c.parent_id ? ' ' : '' }}{{ c.label }}
                                            </option>
                                        </optgroup>
                                    </NativeSelect>
                                </td>
                                <td class="px-4 py-2.5 text-muted-foreground">{{ t.account }}</td>
                                <td
                                    class="whitespace-nowrap px-4 py-2.5 text-right font-medium tabular-nums"
                                    :class="t.amount > 0 ? 'text-emerald-700 dark:text-emerald-400' : ''"
                                >
                                    {{ money(t.amount) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </Card>

                <nav v-if="transactions.total > transactions.data.length" class="flex flex-wrap items-center gap-1" aria-label="Pages">
                    <template v-for="l in pageLinks" :key="l.label">
                        <Link
                            v-if="l.url"
                            :href="l.url"
                            preserve-scroll
                            class="rounded-md px-3 py-1.5 text-sm"
                            :class="l.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                            >{{ l.label }}</Link
                        >
                        <span v-else class="px-3 py-1.5 text-sm text-muted-foreground">{{ l.label }}</span>
                    </template>
                </nav>
            </template>

            <div v-if="rules.length" class="text-sm">
                <button type="button" class="text-muted-foreground underline-offset-4 hover:underline" @click="showRules = !showRules">
                    {{ showRules ? 'Hide' : 'Show' }} payee rules ({{ rules.length }})
                </button>
                <Card v-if="showRules" class="mt-2">
                    <ul class="divide-y">
                        <li v-for="r in rules" :key="r.id" class="flex items-center gap-3 px-4 py-2">
                            <div class="min-w-0 flex-1">
                                Payee contains <span class="font-medium">"{{ r.match }}"</span> → {{ r.category }}
                            </div>
                            <Button size="sm" variant="ghost" class="text-red-600" @click="deleteRule(r.id)">Remove</Button>
                        </li>
                    </ul>
                </Card>
            </div>
        </div>

        <TransactionDialog
            v-model:open="dialogOpen"
            :transaction="editing"
            :accounts="accounts"
            :categories="categories"
            :default-account="f.account ? Number(f.account) : null"
        />
    </AppLayout>
</template>
