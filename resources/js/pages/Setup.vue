<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    token: string;
    pendingMigrations: number;
    hasUser: boolean;
}>();

const status = computed(() => (usePage().props as { flash?: { status?: string } }).flash?.status);

const migrateForm = useForm({ token: props.token });
const userForm = useForm({
    token: props.token,
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const runMigrations = () => migrateForm.post(route('setup.migrate'));
const createUser = () =>
    userForm.post(route('setup.user'), {
        onFinish: () => userForm.reset('password', 'password_confirmation'),
    });
</script>

<template>
    <AuthLayout title="Set up Budget" description="Finish installing the app on this server">
        <Head title="Setup" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">{{ status }}</div>

        <section class="grid gap-3">
            <h2 class="text-sm font-medium">1. Database</h2>
            <p v-if="pendingMigrations > 0" class="text-sm text-muted-foreground">
                {{ pendingMigrations }} database update{{ pendingMigrations === 1 ? '' : 's' }} waiting to run.
            </p>
            <p v-else class="text-sm text-muted-foreground">The database is up to date.</p>
            <Button v-if="pendingMigrations > 0" :disabled="migrateForm.processing" @click="runMigrations">
                <LoaderCircle v-if="migrateForm.processing" class="h-4 w-4 animate-spin" />
                Update database
            </Button>
        </section>

        <section class="mt-8 grid gap-3">
            <h2 class="text-sm font-medium">2. Your account</h2>
            <p v-if="hasUser" class="text-sm text-muted-foreground">
                Your account exists. <TextLink :href="route('login')">Log in</TextLink>. You can now clear SETUP_TOKEN in .env, or keep it to run
                database updates after future deploys.
            </p>
            <p v-else-if="pendingMigrations > 0" class="text-sm text-muted-foreground">Update the database first.</p>
            <form v-else class="grid gap-4" @submit.prevent="createUser">
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="userForm.name" required autocomplete="name" />
                    <InputError :message="userForm.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input id="email" v-model="userForm.email" type="email" required autocomplete="email" />
                    <InputError :message="userForm.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="password">Password (12+ characters)</Label>
                    <Input id="password" v-model="userForm.password" type="password" required autocomplete="new-password" />
                    <InputError :message="userForm.errors.password" />
                </div>
                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <Input id="password_confirmation" v-model="userForm.password_confirmation" type="password" required autocomplete="new-password" />
                </div>
                <Button type="submit" :disabled="userForm.processing">
                    <LoaderCircle v-if="userForm.processing" class="h-4 w-4 animate-spin" />
                    Create account
                </Button>
            </form>
        </section>
    </AuthLayout>
</template>
