<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    BarChart3,
    CalendarClock,
    FileText,
    FileUp,
    Landmark,
    LayoutGrid,
    LineChart,
    PiggyBank,
    Sunset,
    Tags,
} from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    { title: 'Accounts', href: '/accounts', icon: Landmark },
    { title: 'Transactions', href: '/transactions', icon: ArrowLeftRight },
    { title: 'Budgets', href: '/budgets', icon: PiggyBank },
    { title: 'Spending', href: '/spending', icon: BarChart3 },
    { title: 'Expense plan', href: '/plan', icon: CalendarClock },
    { title: 'Investments', href: '/investments', icon: LineChart },
    { title: 'Retirement', href: '/retirement', icon: Sunset },
    { title: 'Reports', href: '/reports', icon: FileText },
    { title: 'Import', href: '/import', icon: FileUp },
    { title: 'Categories', href: '/categories', icon: Tags },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
