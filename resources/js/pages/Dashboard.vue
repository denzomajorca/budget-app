<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

defineProps<{
    accounts: unknown[];
    recentTransactions: unknown[];
}>();
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div class="grid gap-4 md:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle>Accounts</CardTitle>
                        <CardDescription>Balances across checking, savings and cards</CardDescription>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">No accounts yet.</CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>This month's budget</CardTitle>
                        <CardDescription>Spent compared with budgeted, by category</CardDescription>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">No budget set up yet.</CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Net worth</CardTitle>
                        <CardDescription>Assets minus debts</CardDescription>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">Appears once accounts are added.</CardContent>
                </Card>
            </div>
            <Card class="flex-1">
                <CardHeader>
                    <CardTitle>Recent transactions</CardTitle>
                    <CardDescription>The latest activity from all accounts</CardDescription>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    Nothing here yet. Importing CSV and Excel files from your bank is coming in step 3.
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
