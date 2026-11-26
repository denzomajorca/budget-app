<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { centsToInput, money } from '@/lib/money';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Account {
    id: number;
    name: string;
    type: string;
    type_label: string;
    institution: string | null;
    opening_balance: number;
    opening_date: string | null;
    is_archived: boolean;
    is_debt: boolean;
    balance: number;
    transactions_count: number;
}

const props = defineProps<{ accounts: Account[]; types: Record<string, string> }>();

const open = ref(false);
const editing = ref<Account | null>(null);
const confirmDelete = ref(false);
const form = useForm({ name: '', type: 'checking', institution: '', opening_balance: '', opening_date: '', is_archived: false });
const isDebt = computed(() => form.type === 'credit_card' || form.type === 'loan');

const active = computed(() => props.accounts.filter((a) => !a.is_archived));
const archived = computed(() => props.accounts.filter((a) => a.is_archived));
const assets = computed(() => active.value.filter((a) => a.balance > 0).reduce((s, a) => s + a.balance, 0));
const debts = computed(() => active.value.filter((a) => a.balance < 0).reduce((s, a) => s + a.balance, 0));

function startAdd() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    confirmDelete.value = false;
    open.value = true;
}
function startEdit(a: Account) {
    editing.value = a;
    form.clearErrors();
    form.name = a.name;
    form.type = a.type;
    form.institution = a.institution ?? '';
    form.opening_balance = a.opening_balance ? (a.opening_balance < 0 && !a.is_debt ? '-' : '') + centsToInput(a.opening_balance) : '';
    form.opening_date = a.opening_date ?? '';
    form.is_archived = a.is_archived;
    confirmDelete.value = false;
    open.value = true;
}
function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.put(route('accounts.update', editing.value.id), opts);
    else form.post(route('accounts.store'), opts);
}
function destroy() {
    if (!editing.value) return;
    router.delete(route('accounts.destroy', editing.value.id), { preserveScroll: true, onSuccess: () => (open.value = false) });
}
</script>

<template>
    <Head title="Accounts" />
    <AppLayout :breadcrumbs="[{ title: 'Accounts', href: '/accounts' }]">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Accounts</h1>
                    <p class="text-sm text-muted-foreground">Checking, savings, credit cards and loans. Balances update as you add transactions.</p>
                </div>
                <Button @click="startAdd"><Plus class="size-4" />Add account</Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription>You have</CardDescription
                        ><CardTitle class="text-2xl tabular-nums">{{ money(assets) }}</CardTitle></CardHeader
                    >
                </Card>
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription>You owe</CardDescription
                        ><CardTitle class="text-2xl tabular-nums">{{ money(-debts) }}</CardTitle></CardHeader
                    >
                </Card>
                <Card>
                    <CardHeader class="pb-2"
                        ><CardDescription>Net worth</CardDescription
                        ><CardTitle class="text-2xl tabular-nums">{{ money(assets + debts) }}</CardTitle></CardHeader
                    >
                </Card>
            </div>

            <Card v-if="accounts.length === 0">
                <CardContent class="p-6 text-sm text-muted-foreground">
                    No accounts yet. Add your checking account and each credit card first; then you can add transactions to them.
                </CardContent>
            </Card>

            <Card
                v-for="group in [
                    { title: 'Accounts', list: active },
                    { title: 'Closed or hidden', list: archived },
                ].filter((g) => g.list.length)"
                :key="group.title"
            >
                <CardHeader class="pb-2"
                    ><CardTitle class="text-base">{{ group.title }}</CardTitle></CardHeader
                >
                <CardContent class="p-0">
                    <ul class="divide-y">
                        <li v-for="a in group.list" :key="a.id" class="flex items-center gap-3 px-6 py-3">
                            <button type="button" class="min-w-0 flex-1 text-left" @click="startEdit(a)">
                                <div class="truncate font-medium">{{ a.name }}</div>
                                <div class="truncate text-sm text-muted-foreground">
                                    {{ a.type_label }}<template v-if="a.institution"> · {{ a.institution }}</template>
                                </div>
                            </button>
                            <div class="text-right">
                                <div class="font-medium tabular-nums" :class="a.balance < 0 ? 'text-red-600 dark:text-red-400' : ''">
                                    {{ money(a.balance) }}
                                </div>
                                <Link
                                    :href="route('transactions.index', { account: a.id })"
                                    class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                                >
                                    {{ a.transactions_count }} {{ a.transactions_count === 1 ? 'transaction' : 'transactions' }}
                                </Link>
                            </div>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90dvh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ editing ? 'Edit account' : 'Add an account' }}</DialogTitle>
                    <DialogDescription>{{
                        editing ? 'Change the details, hide it, or delete it.' : 'One for each bank account, card or loan.'
                    }}</DialogDescription>
                </DialogHeader>
                <form id="account-form" class="grid gap-4" @submit.prevent="save">
                    <div class="grid gap-2">
                        <Label for="a-name">Name</Label>
                        <Input id="a-name" v-model="form.name" placeholder="Chase Sapphire" required />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="a-type">Type</Label>
                            <NativeSelect id="a-type" v-model="form.type">
                                <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                            </NativeSelect>
                            <InputError :message="form.errors.type" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="a-inst">Bank (optional)</Label>
                            <Input id="a-inst" v-model="form.institution" placeholder="Chase" />
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="a-open">{{ isDebt ? 'Amount owed at the start' : 'Balance at the start' }}</Label>
                            <Input id="a-open" v-model="form.opening_balance" inputmode="decimal" placeholder="0.00" />
                            <InputError :message="form.errors.opening_balance" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="a-date">On this date</Label>
                            <Input id="a-date" v-model="form.opening_date" type="date" />
                            <InputError :message="form.errors.opening_date" />
                        </div>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Use the balance from the day before your first transaction here, so the running balance matches your bank.
                    </p>
                    <label v-if="editing" class="flex items-center gap-2 text-sm">
                        <input v-model="form.is_archived" type="checkbox" class="size-4 rounded border-input" />
                        Closed or hidden (kept for history, left out of totals)
                    </label>
                </form>
                <div
                    v-if="editing && confirmDelete"
                    class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
                >
                    This deletes {{ editing.name }} and its {{ editing.transactions_count }} transactions for good.
                    <div class="mt-2 flex gap-2">
                        <Button variant="destructive" size="sm" @click="destroy">Yes, delete</Button>
                        <Button variant="outline" size="sm" @click="confirmDelete = false">Keep it</Button>
                    </div>
                </div>
                <DialogFooter class="gap-2 sm:justify-between">
                    <Button v-if="editing && !confirmDelete" type="button" variant="ghost" class="text-red-600" @click="confirmDelete = true"
                        >Delete account</Button
                    >
                    <span v-else />
                    <Button type="submit" form="account-form" :disabled="form.processing">Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
