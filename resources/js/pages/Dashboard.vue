<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { money, shortDate } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

defineProps<{
    accounts: { id: number; name: string; type_label: string; balance: number }[];
    netWorth: number;
    investments: number;
    budget: { budgeted: number; spent: number; over: { name: string; over: number }[] };
    spentThisMonth: number;
    monthStart: string;
    recentTransactions: { id: number; date: string; payee: string; amount: number; account: string | null; category: string | null }[];
}>();
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3 [&>*]:min-w-0">
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Net worth</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ money(netWorth) }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground"
                        >What you have minus what you owe, across {{ accounts.length }} accounts<template v-if="investments">
                            and {{ money(investments) }} in
                            <Link :href="route('investments.index')" class="underline-offset-4 hover:underline">investments</Link></template
                        >.</CardContent
                    >
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Spent this month</CardDescription>
                        <CardTitle class="text-2xl tabular-nums">{{ money(spentThisMonth) }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm">
                        <Link
                            :href="route('transactions.index', { from: monthStart })"
                            class="text-muted-foreground underline-offset-4 hover:underline"
                            >See this month's transactions</Link
                        >
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>This month's budget</CardDescription>
                        <CardTitle v-if="budget.budgeted" class="text-2xl tabular-nums">
                            {{ money(budget.spent) }} <span class="text-base font-normal text-muted-foreground">of {{ money(budget.budgeted) }}</span>
                        </CardTitle>
                        <CardTitle v-else class="text-base font-normal text-muted-foreground">No budgets yet</CardTitle>
                    </CardHeader>
                    <CardContent class="grid gap-2 text-sm">
                        <div v-if="budget.budgeted" class="h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full"
                                :class="budget.spent > budget.budgeted ? 'bg-red-500' : 'bg-emerald-600'"
                                :style="{ width: Math.min(100, Math.round((budget.spent / budget.budgeted) * 100)) + '%' }"
                            />
                        </div>
                        <div v-for="o in budget.over" :key="o.name" class="flex justify-between gap-2 text-red-600 dark:text-red-400">
                            <span class="truncate">{{ o.name }}</span
                            ><span class="tabular-nums">{{ money(o.over) }} over</span>
                        </div>
                        <Link :href="route('budgets.index')" class="text-muted-foreground underline-offset-4 hover:underline">{{
                            budget.budgeted ? 'See budgets' : 'Set up budgets'
                        }}</Link>
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-4 lg:grid-cols-[1fr_2fr]">
                <Card>
                    <CardHeader class="flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-base">Accounts</CardTitle>
                        <Link :href="route('accounts.index')" class="text-sm text-muted-foreground hover:underline">Manage</Link>
                    </CardHeader>
                    <CardContent>
                        <p v-if="accounts.length === 0" class="text-sm text-muted-foreground">
                            No accounts yet.
                            <Button as-child variant="link" class="h-auto p-0"
                                ><Link :href="route('accounts.index')">Add your first account</Link></Button
                            >
                        </p>
                        <ul v-else class="divide-y">
                            <li v-for="a in accounts" :key="a.id" class="flex items-center justify-between gap-3 py-2 text-sm">
                                <Link :href="route('transactions.index', { account: a.id })" class="min-w-0 truncate hover:underline">{{
                                    a.name
                                }}</Link>
                                <span class="tabular-nums" :class="a.balance < 0 ? 'text-red-600 dark:text-red-400' : ''">{{
                                    money(a.balance)
                                }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-base">Recent transactions</CardTitle>
                        <Link :href="route('transactions.index')" class="text-sm text-muted-foreground hover:underline">All</Link>
                    </CardHeader>
                    <CardContent>
                        <p v-if="recentTransactions.length === 0" class="text-sm text-muted-foreground">
                            Nothing here yet. Importing bank files comes in step 3.
                        </p>
                        <ul v-else class="divide-y">
                            <li v-for="t in recentTransactions" :key="t.id" class="flex items-center justify-between gap-3 py-2 text-sm">
                                <div class="min-w-0">
                                    <div class="truncate font-medium">{{ t.payee }}</div>
                                    <div class="truncate text-xs text-muted-foreground">
                                        {{ shortDate(t.date) }} · {{ t.category ?? 'Uncategorized' }}
                                    </div>
                                </div>
                                <span class="tabular-nums" :class="t.amount > 0 ? 'text-emerald-700 dark:text-emerald-400' : ''">{{
                                    money(t.amount)
                                }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
