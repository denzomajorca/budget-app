<script setup lang="ts">
import NativeSelect from '@/components/NativeSelect.vue';
import TransactionDialog, { type CategoryOption, type Txn } from '@/components/TransactionDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { money, shortDate } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Plus, X } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

interface Row extends Txn {
    account: string | null;
    category: string | null;
    is_transfer: boolean;
    transfer: string | null;
}
interface Filters {
    account: string | number | null;
    category: string | null;
    from: string | null;
    to: string | null;
    q: string | null;
    import: string | number | null;
    transfers: string | null;
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
}>();

const f = reactive({
    account: props.filters.account ? String(props.filters.account) : '',
    category: props.filters.category ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    q: props.filters.q ?? '',
    import: props.filters.import ? String(props.filters.import) : '',
    transfers: props.filters.transfers ?? '',
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
    Object.assign(f, { account: '', category: '', from: '', to: '', q: '', import: '', transfers: '' });
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
                        <option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.parent_id ? ' ' : '' }}{{ c.label }}</option>
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
                <!-- Phones: a simple list -->
                <Card class="md:hidden">
                    <ul class="divide-y">
                        <li v-for="t in transactions.data" :key="t.id">
                            <button type="button" class="flex w-full items-start gap-3 px-4 py-3 text-left" @click="edit(t)">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium">{{ t.payee }}</div>
                                    <div class="truncate text-sm text-muted-foreground">{{ categoryText(t) }}</div>
                                    <div class="truncate text-xs text-muted-foreground">{{ shortDate(t.date) }} · {{ t.account }}</div>
                                </div>
                                <div class="font-medium tabular-nums" :class="t.amount > 0 ? 'text-emerald-700 dark:text-emerald-400' : ''">
                                    {{ money(t.amount) }}
                                </div>
                            </button>
                        </li>
                    </ul>
                </Card>
                <!-- Larger screens: a table -->
                <Card class="hidden overflow-hidden md:block">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
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
                                tabindex="0"
                                @click="edit(t)"
                                @keydown.enter="edit(t)"
                            >
                                <td class="whitespace-nowrap px-4 py-2.5">{{ shortDate(t.date) }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="font-medium">{{ t.payee }}</div>
                                    <div v-if="t.memo" class="text-xs text-muted-foreground">{{ t.memo }}</div>
                                </td>
                                <td class="px-4 py-2.5" :class="t.category && !t.is_transfer ? '' : 'text-muted-foreground'">
                                    {{ categoryText(t) }}
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
