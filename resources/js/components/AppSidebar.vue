<script setup lang="ts">
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { isActivePath, visibleGroups } from '@/lib/navigation';
import { useNavLock } from '@/Pos/composables/useNavLock';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Store as StoreIcon } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const can = computed(() => page.props.auth.can);
const storeName = computed(() => page.props.auth.store_name);
const currentPath = computed(() => new URL(page.url, 'http://x').pathname);

const groups = computed(() => visibleGroups(can.value));

const isActive = (href: string) => isActivePath(href, currentPath.value);

/*
 * The POS locks navigation while it holds unsynced sales. Leaving would
 * unload the page that owns the flush loop, so the queue would sit untouched.
 * The current page stays clickable so the lock can never trap focus.
 */
const navLock = useNavLock();

const isBlocked = (href: string) => navLock.locked && !isActive(href);
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
            <!-- Which shop you are standing in. A multi-store operator should
                 never have to guess which stock they are looking at. -->
            <SidebarGroup v-if="storeName" class="py-1">
                <SidebarGroupContent>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton :tooltip="storeName" class="pointer-events-none">
                                <StoreIcon class="text-primary" />
                                <span class="truncate font-medium">{{ storeName }}</span>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroupContent>
            </SidebarGroup>

            <SidebarGroup v-for="group in groups" :key="group.label">
                <SidebarGroupLabel class="font-mono text-[0.65rem] uppercase tracking-[0.14em]">
                    {{ group.label }}
                </SidebarGroupLabel>
                <SidebarGroupContent>
                    <SidebarMenu>
                        <SidebarMenuItem v-for="item in group.items" :key="item.href">
                            <SidebarMenuButton
                                v-if="isBlocked(item.href)"
                                :tooltip="navLock.reason ?? 'Finish syncing first'"
                                class="cursor-not-allowed opacity-40"
                                aria-disabled="true"
                            >
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </SidebarMenuButton>
                            <SidebarMenuButton v-else as-child :is-active="isActive(item.href)" :tooltip="item.title" class="press">
                                <Link :href="item.href">
                                    <component :is="item.icon" />
                                    <span>{{ item.title }}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroupContent>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
