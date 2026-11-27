<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { groupByKind } from '@/lib/categories';
import { centsToInput, money, today } from '@/lib/money';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

export type Group = 'bill' | 'flex' | 'goal';
export interface PlanItem {
    id: number;
    group: Group;
    name: string;
    category_id: number | null;
    category: string | null;
    amount: number;
    frequency_key: string | null;
    frequency: string | null;
    next_due: string | null;
    is_estimate: boolean;
    per_month: number;
    saved: number;
    due_by: string | null;
    average: number | null;
}
export interface CategoryOption {
    id: number;
    label: string;
    parent_id: number | null;
    average: number | null;
}

const props = defineProps<{ group: Group; item: PlanItem | null; categories: CategoryOption[]; frequencies: Record<string, string> }>();
const open = defineModel<boolean>('open', { required: true });

const titles = { bill: 'bill', flex: 'everyday expense', goal: 'planned expense' };
const confirmDelete = ref(false);
const nextYear = () => {
    const d = new Date();
    return `${d.getFullYear() + 1}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
};
const form = useForm({
    group: props.group as Group,
    name: '',
    category_id: null as number | null,
    amount: '',
    frequency: '1m',
    next_due: today(),
    is_estimate: false,
    saved: '',
    due_by: nextYear(),
});

watch(open, (o) => {
    if (!o) return;
    const i = props.item;
    confirmDelete.value = false;
    form.clearErrors();
    form.group = i?.group ?? props.group;
    form.name = i?.name ?? '';
    form.category_id = i?.category_id ?? null;
    form.amount = i ? centsToInput(i.amount) : '';
    form.frequency = i?.frequency_key ?? '1m';
    form.next_due = i?.next_due ?? today();
    form.is_estimate = i?.is_estimate ?? false;
    form.saved = i && i.saved ? centsToInput(i.saved) : '';
    form.due_by = i?.due_by ?? nextYear();
});

const avg = computed(() => props.categories.find((c) => c.id === form.category_id)?.average ?? null);
function categoryPicked() {
    const c = props.categories.find((x) => x.id === form.category_id);
    if (!c) return;
    if (!form.name) form.name = c.label.split(' › ').pop() ?? '';
    if (form.group === 'flex' && !form.amount && c.average) form.amount = String(Math.ceil(c.average / 100));
}
function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (props.item) form.put(route('plan.update', props.item.id), opts);
    else form.post(route('plan.store'), opts);
}
function destroy() {
    if (props.item) router.delete(route('plan.destroy', props.item.id), { preserveScroll: true, onSuccess: () => (open.value = false) });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90dvh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ item ? 'Edit' : 'Add a' }} {{ titles[form.group] }}</DialogTitle>
                <DialogDescription v-if="form.group === 'bill'"
                    >Something due on a schedule, like the mortgage, electric or insurance.</DialogDescription
                >
                <DialogDescription v-else-if="form.group === 'flex'"
                    >Spending with no fixed bill, like groceries or charity. Set what you plan to spend a month.</DialogDescription
                >
                <DialogDescription v-else>A bigger one-time cost you save toward, like a vacation or a home project.</DialogDescription>
            </DialogHeader>
            <form id="plan-form" class="grid gap-4" @submit.prevent="save">
                <div class="grid gap-2">
                    <Label for="p-cat">Category</Label>
                    <NativeSelect id="p-cat" v-model="form.category_id" @change="categoryPicked">
                        <option :value="null">None</option>
                        <optgroup v-for="g in groupByKind(categories)" :key="g.kind" :label="g.title">
                            <option v-for="c in g.options" :key="c.id" :value="c.id">{{ c.parent_id ? ' ' : '' }}{{ c.label }}</option>
                        </optgroup>
                    </NativeSelect>
                    <p v-if="avg" class="text-xs text-muted-foreground">You've spent about {{ money(avg) }} a month on this lately.</p>
                </div>
                <div class="grid gap-2">
                    <Label for="p-name">Name</Label>
                    <Input
                        id="p-name"
                        v-model="form.name"
                        required
                        :placeholder="form.group === 'goal' ? 'Europe vacation' : form.group === 'bill' ? 'Mortgage' : 'Groceries'"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="p-amt">{{
                            form.group === 'goal' ? 'Total cost' : form.group === 'flex' ? 'Plan per month' : 'Amount each time'
                        }}</Label>
                        <Input id="p-amt" v-model="form.amount" inputmode="decimal" placeholder="0.00" required />
                        <InputError :message="form.errors.amount" />
                    </div>
                    <div v-if="form.group === 'bill'" class="grid gap-2">
                        <Label for="p-freq">How often</Label>
                        <NativeSelect id="p-freq" v-model="form.frequency">
                            <option v-for="(l, k) in frequencies" :key="k" :value="k">{{ l }}</option>
                        </NativeSelect>
                    </div>
                    <div v-if="form.group === 'goal'" class="grid gap-2">
                        <Label for="p-saved">Already saved</Label>
                        <Input id="p-saved" v-model="form.saved" inputmode="decimal" placeholder="0.00" />
                        <InputError :message="form.errors.saved" />
                    </div>
                </div>
                <div v-if="form.group === 'bill'" class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="p-next">Next due</Label>
                        <Input id="p-next" v-model="form.next_due" type="date" required />
                        <InputError :message="form.errors.next_due" />
                    </div>
                    <label class="flex items-center gap-2 self-end pb-2 text-sm">
                        <input v-model="form.is_estimate" type="checkbox" class="size-4" /> The amount changes (an estimate)
                    </label>
                </div>
                <div v-if="form.group === 'goal'" class="grid gap-2">
                    <Label for="p-by">Needed by</Label>
                    <Input id="p-by" v-model="form.due_by" type="date" required />
                    <InputError :message="form.errors.due_by" />
                </div>
            </form>
            <div
                v-if="item && confirmDelete"
                class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
            >
                Remove {{ item.name }} from your plan?
                <div class="mt-2 flex gap-2">
                    <Button variant="destructive" size="sm" @click="destroy">Yes, remove</Button>
                    <Button variant="outline" size="sm" @click="confirmDelete = false">Keep it</Button>
                </div>
            </div>
            <DialogFooter class="gap-2 sm:justify-between">
                <Button v-if="item && !confirmDelete" type="button" variant="ghost" class="text-red-600" @click="confirmDelete = true">Remove</Button>
                <span v-else />
                <Button type="submit" form="plan-form" :disabled="form.processing">Save</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
