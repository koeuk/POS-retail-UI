<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<SharedData>();

/* Shop settings change what every cashier and customer sees, so the entry is
   admin-only — a manager should not be able to flip the currency. */
const sidebarNavItems = computed<NavItem[]>(() => [
    { title: 'Profile', href: '/settings/profile' },
    { title: 'Password', href: '/settings/password' },
    { title: 'Appearance', href: '/settings/appearance' },
    ...(page.props.auth.can.isAdmin
        ? [
              { title: 'Shop', href: '/settings/shop' },
              { title: 'Payments', href: '/settings/payments' },
          ]
        : []),
]);

/* Follows Inertia navigation; window.location would go stale after a visit. */
const currentPath = computed(() => new URL(page.url, 'http://x').pathname);
</script>

<template>
    <div class="px-4 py-6">
        <Heading title="Settings" description="Manage your profile and account settings" />

        <div class="flex flex-col space-y-8 md:space-y-0 lg:flex-row lg:space-x-12 lg:space-y-0">
            <!--
                Phone: one row of tabs. The stacked list cost four rows of a
                430px screen before the page itself began. It scrolls sideways
                rather than wrapping, so adding a fifth section never pushes
                the content down; the underline marks the current section the
                way every mobile tab bar does.
            -->
            <nav class="scrollbar-none -mx-4 flex gap-1 overflow-x-auto border-b border-border px-4 lg:hidden" aria-label="Settings sections">
                <Link
                    v-for="item in sidebarNavItems"
                    :key="item.href"
                    :href="item.href"
                    class="press -mb-px shrink-0 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition-colors"
                    :class="
                        currentPath === item.href
                            ? 'border-primary text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                    :aria-current="currentPath === item.href ? 'page' : undefined"
                >
                    {{ item.title }}
                </Link>
            </nav>

            <!-- Desktop keeps the sidebar: there is room, and it matches the app. -->
            <aside class="hidden w-full max-w-xl lg:block lg:w-48">
                <nav class="flex flex-col space-x-0 space-y-1">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['w-full justify-start rounded-xl', { 'bg-accent text-accent-foreground': currentPath === item.href }]"
                        as-child
                    >
                        <Link :href="item.href">
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <div class="min-w-0 flex-1">
                <section class="space-y-12 rounded-xl border border-border bg-card p-4 md:p-6">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
