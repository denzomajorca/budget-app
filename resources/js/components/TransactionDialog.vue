<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { centsToInput, today } from '@/lib/money';
import { router, useForm } from '@inertiajs/vue3';
import { nextTick, ref, watch } from 'vue';

export interface Txn {
    id: number;
    date: string;
    payee: string;
    amount: number;
    memo: string | null;
    account_id: number;
    category_id: number | null;
}
export interface CategoryOption {
    id: number;
    label: string;
    parent_id: number | null;
    kind: string;
}

const props = defineProps<{
    transaction: Txn | null;
    accounts: { id: number; name: string; is_archived: boolean }[];
    categories: CategoryOption[];
    defaultAccount?: number | null;
}>();
const open = defineModel<boolean>('open', { required: true });

const confirmDelete = ref(false);
const form = useForm({
    account_id: null as number | null,
    date: today(),
    payee: '',
    direction: 'out',
    amount: '',
    category_id: null as number | null,
    memo: '',
});

watch(open, (isOpen) => {
    if (!isOpen) return;
    const t = props.transaction;
    form.clearErrors();
    confirmDelete.value = false;
    form.account_id = t?.account_id ?? props.defaultAccount ?? props.accounts.find((a) => !a.is_archived)?.id ?? null;
    form.date = t?.date ?? today();
    form.payee = t?.payee ?? '';
    form.direction = t && t.amount > 0 ? 'in' : 'out';
    form.amount = t ? centsToInput(t.amount) : '';
    form.category_id = t?.category_id ?? null;
    form.memo = t?.memo ?? '';
});

// Picking an income category switches the amount to money in, and back.
async function categoryPicked() {
    await nextTick();
    const kind = props.categories.find((c) => c.id === form.category_id)?.kind;
    if (kind === 'income') form.direction = 'in';
    else if (kind === 'expense') form.direction = 'out';
}

function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (props.transaction) form.put(route('transactions.update', props.transaction.id), opts);
    else form.post(route('transactions.store'), opts);
}
function destroy() {
    if (!props.transaction) return;
    router.delete(route('transactions.destroy', props.transaction.id), { preserveScroll: true, onSuccess: () => (open.value = false) });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90dvh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ transaction ? 'Edit transaction' : 'Add a transaction' }}</DialogTitle>
                <DialogDescription>Bank files come in step 3; this is for adding or fixing one at a time.</DialogDescription>
            </DialogHeader>
            <p v-if="accounts.length === 0" class="text-sm">Add an account first, on the Accounts page.</p>
            <form v-else id="txn-form" class="grid gap-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="t-date">Date</Label>
                        <Input id="t-date" v-model="form.date" type="date" required />
                        <InputError :message="form.errors.date" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="t-acct">Account</Label>
                        <NativeSelect id="t-acct" v-model="form.account_id">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </NativeSelect>
                        <InputError :message="form.errors.account_id" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="t-payee">Payee</Label>
                    <Input id="t-payee" v-model="form.payee" placeholder="Publix" required />
                    <InputError :message="form.errors.payee" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <span class="text-sm font-medium">Type</span>
                        <div class="inline-flex rounded-md border p-0.5" role="group" aria-label="Money out or in">
                            <button
                                v-for="d in [
                                    ['out', 'Money out'],
                                    ['in', 'Money in'],
                                ]"
                                :key="d[0]"
                                type="button"
                                class="flex-1 rounded px-3 py-1.5 text-sm"
                                :class="form.direction === d[0] ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'"
                                :aria-pressed="form.direction === d[0]"
                                @click="form.direction = d[0]"
                            >
                                {{ d[1] }}
                            </button>
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="t-amt">Amount</Label>
                        <Input id="t-amt" v-model="form.amount" inputmode="decimal" placeholder="0.00" required />
                        <InputError :message="form.errors.amount" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="t-cat">Category</Label>
                    <NativeSelect id="t-cat" v-model="form.category_id" @change="categoryPicked">
                        <option :value="null">Uncategorized</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.parent_id ? ' ' : '' }}{{ c.label }}</option>
                    </NativeSelect>
                    <InputError :message="form.errors.category_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="t-memo">Memo (optional)</Label>
                    <Input id="t-memo" v-model="form.memo" />
                    <InputError :message="form.errors.memo" />
                </div>
            </form>
            <div
                v-if="transaction && confirmDelete"
                class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
            >
                Delete this transaction?
                <div class="mt-2 flex gap-2">
                    <Button variant="destructive" size="sm" @click="destroy">Yes, delete</Button>
                    <Button variant="outline" size="sm" @click="confirmDelete = false">Keep it</Button>
                </div>
            </div>
            <DialogFooter class="gap-2 sm:justify-between">
                <Button v-if="transaction && !confirmDelete" type="button" variant="ghost" class="text-red-600" @click="confirmDelete = true"
                    >Delete</Button
                >
                <span v-else />
                <Button v-if="accounts.length" type="submit" form="txn-form" :disabled="form.processing">Save</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
