<script setup lang="ts">
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { guessAccount, type MatchAccount, type MatchCounts } from '@/lib/batch';
import { guessDateFormat, guessMapping, headerRowIndex, readFile, toRows, type ParsedRow, type Settings, type Table } from '@/lib/importer';
import { shortDate } from '@/lib/money';
import { router } from '@inertiajs/vue3';
import { FileText } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps<{ accounts: MatchAccount[]; files: File[] }>();
const emit = defineEmits<{ single: [file: File]; cancel: [] }>();

interface Item {
    file: File;
    headers: string[];
    body: Table;
    firstLine: number;
    readError: string;
    accountId: number | null;
    reason: string;
    settings: Settings | null;
    usedSaved: boolean;
    rows: ParsedRow[];
    counts: MatchCounts | null;
    include: boolean;
}
const items = reactive<Item[]>([]);
const loading = ref(true);
const error = ref('');
const newCategories = ref<string[]>([]);
const includeDuplicates = ref(false);
const saving = ref(false);

const sameHeaders = (s: Settings | null | undefined, h: string[]) => !!s && JSON.stringify(s.headers) === JSON.stringify(h);

function settingsFor(it: Item, accountId: number | null): { settings: Settings; saved: boolean } {
    const acct = props.accounts.find((a) => a.id === accountId);
    const saved = acct?.import_settings;
    if (saved && sameHeaders(saved, it.headers)) return { settings: JSON.parse(JSON.stringify(saved)), saved: true };
    // Before an account is picked, borrow the settings of any account that used these same columns.
    const twin = accountId === null ? props.accounts.find((a) => sameHeaders(a.import_settings, it.headers))?.import_settings : null;
    if (twin) return { settings: JSON.parse(JSON.stringify(twin)), saved: false };
    const mapping = guessMapping(it.headers);
    return {
        settings: {
            mapping,
            headers: [...it.headers],
            dateFormat: guessDateFormat(it.body.slice(0, 200).map((r) => r[mapping.date] ?? null)),
            sign: saved?.sign ?? 'neg-out',
        },
        saved: false,
    };
}

function columnsProblem(s: Settings | null): string {
    if (!s) return 'Could not read it';
    const m = s.mapping;
    if (m.date < 0 || m.payee < 0 || (m.amount < 0 && m.debit < 0 && m.credit < 0)) return 'The columns need checking';
    return '';
}

function apply(it: Item, accountId: number | null) {
    it.accountId = accountId;
    const { settings, saved } = settingsFor(it, accountId);
    it.settings = settings;
    it.usedSaved = saved;
    it.rows = toRows(it.body, it.firstLine, settings);
    it.include = accountId !== null && !problem(it) && fresh(it) > 0;
}

const good = (it: Item) => it.rows.filter((r) => !r.error);
function problem(it: Item): string {
    if (it.readError) return it.readError;
    const c = columnsProblem(it.settings);
    if (c) return c;
    const ok = good(it).length;
    if (!ok) return 'No rows could be read';
    if (ok < it.rows.length / 2) return 'Many rows could not be read';
    return '';
}
const range = (it: Item) => {
    const d = good(it)
        .map((r) => r.date!)
        .sort();
    return d.length ? `${shortDate(d[0])} to ${shortDate(d[d.length - 1])}` : '';
};
const already = (it: Item) => (it.accountId && it.counts ? (it.counts.exact[it.accountId] ?? 0) : 0);
const fresh = (it: Item) => (includeDuplicates.value ? good(it).length : Math.max(0, good(it).length - already(it)));

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

onMounted(async () => {
    for (const file of props.files) {
        const it: Item = {
            file,
            headers: [],
            body: [],
            firstLine: 2,
            readError: '',
            accountId: null,
            reason: '',
            settings: null,
            usedSaved: false,
            rows: [],
            counts: null,
            include: false,
        };
        try {
            const table = await readFile(file);
            if (table.length < 2) throw new Error('it has no rows');
            const h = headerRowIndex(table);
            it.headers = table[h].map((c, i) => String(c ?? '').trim() || `Column ${i + 1}`);
            it.body = table.slice(h + 1);
            it.firstLine = h + 2;
            apply(it, null);
        } catch (e) {
            it.readError = `Could not read it: ${(e as Error).message}`;
        }
        items.push(it);
    }
    const readable = items.filter((it) => !it.readError && !columnsProblem(it.settings) && good(it).length);
    try {
        if (readable.length) {
            const res = await fetch(route('imports.match'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    files: readable.map((it) => ({
                        rows: good(it).map((r) => ({ date: r.date, amount: r.amount, payee: r.payee, category: r.category || null })),
                    })),
                }),
            });
            const json = await res.json();
            if (!res.ok) throw new Error(json.message ?? 'The server could not check these files.');
            readable.forEach((it, i) => (it.counts = json.files[i]));
            newCategories.value = json.new_categories;
        }
    } catch (e) {
        error.value = (e as Error).message;
    }
    for (const it of items) {
        if (it.readError) continue;
        const g = guessAccount(it.file.name, it.headers, good(it).length, it.counts, props.accounts);
        it.reason = g.reason;
        apply(it, g.accountId);
    }
    loading.value = false;
});

function choose(it: Item, v: string | number | null) {
    const id = v === '' || v === null ? null : Number(v);
    apply(it, id);
    it.reason = id === null ? 'Pick the account for this file' : 'You picked this';
}

const chosen = computed(() => items.filter((it) => it.include && it.accountId && !problem(it)));
const total = computed(() => chosen.value.reduce((s, it) => s + fresh(it), 0));
const shared = computed(() => {
    const seen = new Map<number, number>();
    chosen.value.forEach((it) => seen.set(it.accountId!, (seen.get(it.accountId!) ?? 0) + 1));
    return [...seen.entries()].filter(([, n]) => n > 1).map(([id]) => props.accounts.find((a) => a.id === id)?.name ?? '');
});

function commit() {
    if (!chosen.value.length) return;
    saving.value = true;
    error.value = '';
    router.post(
        route('imports.batch'),
        {
            include_duplicates: includeDuplicates.value,
            files: chosen.value.map((it) => ({
                account_id: it.accountId,
                file_name: it.file.name,
                skipped: it.rows.length - good(it).length,
                settings: JSON.parse(JSON.stringify(it.settings)),
                rows: good(it).map((r) => ({ date: r.date, amount: r.amount, payee: r.payee, category: r.category || null, memo: r.memo || null })),
            })),
        },
        {
            onSuccess: () => window.scrollTo(0, 0),
            onError: (errors) => (error.value = Object.values(errors)[0] ?? 'The import did not go through.'),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">{{ files.length }} files</CardTitle>
            <CardDescription>
                <template v-if="loading">Reading the files and matching each one to an account…</template>
                <template v-else>Each file is matched to an account. Check the matches, change any that are wrong, then import.</template>
            </CardDescription>
        </CardHeader>
        <CardContent v-if="!loading" class="grid gap-4">
            <ul class="grid grid-cols-1 gap-3">
                <li v-for="(it, i) in items" :key="i" class="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_16rem] sm:items-start">
                    <div class="flex min-w-0 gap-3">
                        <input
                            v-model="it.include"
                            type="checkbox"
                            class="mt-1 size-4 shrink-0"
                            :disabled="!it.accountId || !!problem(it)"
                            :aria-label="`Import ${it.file.name}`"
                        />
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5 font-medium">
                                <FileText class="size-4 shrink-0 text-muted-foreground" /><span class="truncate">{{ it.file.name }}</span>
                            </div>
                            <template v-if="problem(it)">
                                <p class="text-sm text-red-600">{{ problem(it) }}.</p>
                                <Button v-if="!it.readError" variant="link" class="h-auto p-0 text-sm" @click="emit('single', it.file)"
                                    >Set up this file on its own</Button
                                >
                            </template>
                            <template v-else>
                                <p class="text-sm text-muted-foreground">{{ good(it).length }} transactions, {{ range(it) }}</p>
                                <p v-if="it.accountId" class="text-sm">
                                    <span class="font-medium">{{ fresh(it) }} new</span
                                    ><template v-if="already(it) && !includeDuplicates">, {{ already(it) }} already there and skipped</template>
                                </p>
                            </template>
                        </div>
                    </div>
                    <div v-if="!it.readError" class="grid gap-1">
                        <NativeSelect
                            :model-value="it.accountId ?? ''"
                            :aria-label="`Account for ${it.file.name}`"
                            @update:model-value="(v: string | number | null) => choose(it, v)"
                        >
                            <option value="">Pick an account</option>
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </NativeSelect>
                        <span class="text-xs" :class="it.accountId ? 'text-muted-foreground' : 'text-amber-700 dark:text-amber-400'">{{
                            it.reason
                        }}</span>
                    </div>
                </li>
            </ul>

            <ul class="grid gap-2 text-sm">
                <li v-if="shared.length" class="rounded-md bg-muted p-3">
                    More than one file goes into {{ shared.join(' and ') }}. That's fine: a transaction in two files is only added once.
                </li>
                <li v-if="newCategories.length" class="rounded-md bg-muted p-3">
                    {{ newCategories.length }} new {{ newCategories.length === 1 ? 'category' : 'categories' }} will be added:
                    {{ newCategories.slice(0, 12).join(', ')
                    }}<template v-if="newCategories.length > 12">, and {{ newCategories.length - 12 }} more</template>.
                </li>
                <li>
                    <label class="flex items-center gap-2"
                        ><input v-model="includeDuplicates" type="checkbox" class="size-4" /> Also import transactions that are already there</label
                    >
                </li>
            </ul>
            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" @click="emit('cancel')">Back</Button>
                <Button :disabled="saving || !chosen.length || total === 0" @click="commit">
                    {{
                        saving
                            ? 'Importing…'
                            : `Import ${total} ${total === 1 ? 'transaction' : 'transactions'} from ${chosen.length} ${chosen.length === 1 ? 'file' : 'files'}`
                    }}
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">Each file can be undone on its own afterwards, under Recent imports.</p>
        </CardContent>
    </Card>
</template>
