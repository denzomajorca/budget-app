<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Sub {
    id: number;
    name: string;
    count: number;
}
interface Main extends Sub {
    kind: string;
    children: Sub[];
}

const props = defineProps<{
    categories: Main[];
    options: { id: number; label: string; parent_id: number | null }[];
    kinds: Record<string, string>;
}>();

const open = ref(false);
const editing = ref<{ id: number; name: string; parent_id: number | null; count: number; childCount: number } | null>(null);
const deleting = ref(false);
const form = useForm({ name: '', parent_id: null as number | null, kind: 'expense' });
const del = useForm({ move_to: null as number | null });

const mains = computed(() => props.categories.map((c) => ({ id: c.id, name: c.name })));
const moveOptions = computed(() => {
    const e = editing.value;
    if (!e) return [];
    const blocked = new Set([e.id, ...(props.categories.find((c) => c.id === e.id)?.children.map((c) => c.id) ?? [])]);
    return props.options.filter((o) => !blocked.has(o.id));
});
const total = (m: Main) => m.count + m.children.reduce((s, c) => s + c.count, 0);

function startAdd(parentId: number | null = null) {
    editing.value = null;
    deleting.value = false;
    form.clearErrors();
    form.name = '';
    form.parent_id = parentId;
    form.kind = 'expense';
    open.value = true;
}
function startEdit(c: Sub, parentId: number | null, kind: string, childCount = 0) {
    editing.value = { id: c.id, name: c.name, parent_id: parentId, count: c.count, childCount };
    deleting.value = false;
    form.clearErrors();
    del.move_to = null;
    form.name = c.name;
    form.parent_id = parentId;
    form.kind = kind;
    open.value = true;
}
function save() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.put(route('categories.update', editing.value.id), opts);
    else form.post(route('categories.store'), opts);
}
function destroy() {
    if (!editing.value) return;
    del.delete(route('categories.destroy', editing.value.id), { preserveScroll: true, onSuccess: () => (open.value = false) });
}
</script>

<template>
    <Head title="Categories" />
    <AppLayout :breadcrumbs="[{ title: 'Categories', href: '/categories' }]">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Categories</h1>
                    <p class="text-sm text-muted-foreground">
                        Main categories and subcategories, like Food and Dining › Groceries. Imports add any that are missing.
                    </p>
                </div>
                <Button @click="startAdd()"><Plus class="size-4" />Add category</Button>
            </div>

            <Card v-for="m in categories" :key="m.id">
                <CardContent class="p-0">
                    <div class="flex items-center gap-3 px-4 py-3">
                        <button type="button" class="min-w-0 flex-1 text-left font-semibold" @click="startEdit(m, null, m.kind, m.children.length)">
                            {{ m.name }}
                            <span v-if="m.kind !== 'expense'" class="ml-2 rounded bg-muted px-1.5 py-0.5 text-xs font-normal text-muted-foreground">{{
                                kinds[m.kind]
                            }}</span>
                        </button>
                        <Link :href="route('transactions.index', { category: m.id })" class="text-sm text-muted-foreground hover:underline">{{
                            total(m)
                        }}</Link>
                        <Button variant="ghost" size="sm" @click="startAdd(m.id)"
                            ><Plus class="size-4" /><span class="sr-only sm:not-sr-only">Sub</span></Button
                        >
                    </div>
                    <ul v-if="m.children.length" class="divide-y border-t">
                        <li v-for="c in m.children" :key="c.id" class="flex items-center gap-3 py-2 pl-8 pr-4 text-sm">
                            <button type="button" class="min-w-0 flex-1 truncate text-left" @click="startEdit(c, m.id, m.kind)">{{ c.name }}</button>
                            <Link :href="route('transactions.index', { category: c.id })" class="text-muted-foreground hover:underline">{{
                                c.count
                            }}</Link>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90dvh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ editing ? 'Edit category' : form.parent_id ? 'Add a subcategory' : 'Add a category' }}</DialogTitle>
                    <DialogDescription>Leave "Inside" empty for a main category.</DialogDescription>
                </DialogHeader>
                <form id="cat-form" class="grid gap-4" @submit.prevent="save">
                    <div class="grid gap-2">
                        <Label for="c-name">Name</Label>
                        <Input id="c-name" v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="c-parent">Inside</Label>
                        <NativeSelect id="c-parent" v-model="form.parent_id" :disabled="!!editing && editing.childCount > 0">
                            <option :value="null">Nothing (main category)</option>
                            <option v-for="o in mains.filter((x) => x.id !== editing?.id)" :key="o.id" :value="o.id">{{ o.name }}</option>
                        </NativeSelect>
                        <InputError :message="form.errors.parent_id" />
                    </div>
                    <div v-if="!form.parent_id" class="grid gap-2">
                        <Label for="c-kind">Used for</Label>
                        <NativeSelect id="c-kind" v-model="form.kind">
                            <option v-for="(label, key) in kinds" :key="key" :value="key">{{ label }}</option>
                        </NativeSelect>
                        <p class="text-xs text-muted-foreground">Income and Transfer categories are left out of spending totals.</p>
                    </div>
                </form>
                <div
                    v-if="editing && deleting"
                    class="grid gap-2 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
                >
                    <span>
                        Delete {{ editing.name }}<template v-if="editing.childCount"> and its {{ editing.childCount }} subcategories</template>? Move
                        its transactions to:
                    </span>
                    <NativeSelect v-model="del.move_to" aria-label="Move transactions to" class="bg-background text-foreground">
                        <option :value="null">Uncategorized</option>
                        <option v-for="o in moveOptions" :key="o.id" :value="o.id">{{ o.parent_id ? ' ' : '' }}{{ o.label }}</option>
                    </NativeSelect>
                    <InputError :message="del.errors.move_to" />
                    <div class="flex gap-2">
                        <Button variant="destructive" size="sm" :disabled="del.processing" @click="destroy">Yes, delete</Button>
                        <Button variant="outline" size="sm" @click="deleting = false">Keep it</Button>
                    </div>
                </div>
                <DialogFooter class="gap-2 sm:justify-between">
                    <Button v-if="editing && !deleting" type="button" variant="ghost" class="text-red-600" @click="deleting = true">Delete</Button>
                    <span v-else />
                    <Button type="submit" form="cat-form" :disabled="form.processing">Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
