<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { haptic } from '@/composables/useTelegram';
import { isActivePath, moreTab, overflowItems, tabItems } from '@/lib/navigation';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { LogOut, Settings } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage<SharedData>();
const can = computed(() => page.props.auth.can);
const user = computed(() => page.props.auth.user);
const path = computed(() => new URL(page.url, 'http://x').pathname);

const tabs = computed(() => tabItems(can.value));
const overflow = computed(() => overflowItems(can.value));

const moreOpen = ref(false);

/** "More" counts as active whenever the current screen lives inside it. */
const moreActive = computed(() => overflow.value.some((item) => isActivePath(item.href, path.value)) || path.value.startsWith('/settings'));

function openMore() {
    haptic();
    moreOpen.value = true;
}

function go(href: string) {
    moreOpen.value = false;
    router.visit(href);
}
</script>

<template>
    <!--
        Fixed rather than sticky: a sticky bar inside a scroll container drifts
        during momentum scrolling on iOS, and the tab bar is the one piece of
        chrome that must never appear to move.
    -->
    <nav
        class="fixed inset-x-0 bottom-0 z-40 border-t border-border/60 bg-card/70 backdrop-blur-xl md:hidden"
        style="padding-bottom: var(--safe-bottom)"
        aria-label="Primary"
    >
        <ul class="flex items-stretch" :style="{ height: 'var(--tabbar-h)' }">
            <li v-for="tab in tabs" :key="tab.href" class="min-w-0 flex-1">
                <Link
                    :href="tab.href"
                    class="flex h-full w-full min-w-0 flex-col items-center justify-center gap-0.5 transition-colors"
                    :class="isActivePath(tab.href, path) ? 'text-primary' : 'text-muted-foreground'"
                    :aria-current="isActivePath(tab.href, path) ? 'page' : undefined"
                    @click="haptic()"
                >
                    <component :is="tab.icon" class="size-5 shrink-0" :stroke-width="isActivePath(tab.href, path) ? 2.4 : 1.8" />
                    <span class="w-full truncate px-1 text-center text-[0.65rem] font-medium leading-none">{{ tab.title }}</span>
                </Link>
            </li>

            <li class="min-w-0 flex-1">
                <button
                    type="button"
                    class="flex h-full w-full min-w-0 flex-col items-center justify-center gap-0.5 transition-colors"
                    :class="moreActive ? 'text-primary' : 'text-muted-foreground'"
                    :aria-expanded="moreOpen"
                    @click="openMore"
                >
                    <component :is="moreTab.icon" class="size-5 shrink-0" :stroke-width="moreActive ? 2.4 : 1.8" />
                    <span class="w-full truncate px-1 text-center text-[0.65rem] font-medium leading-none">{{ moreTab.title }}</span>
                </button>
            </li>
        </ul>
    </nav>

    <!-- Overflow destinations + account, as a bottom sheet. -->
    <Sheet v-model:open="moreOpen">
        <SheetContent side="bottom" class="rounded-t-2xl px-0 pb-0">
            <SheetHeader class="border-b border-border px-5 text-left">
                <SheetTitle class="sr-only">More</SheetTitle>
                <div v-if="user" class="flex items-center gap-3 pb-2">
                    <UserInfo :user="user" :show-email="true" />
                </div>
            </SheetHeader>

            <div class="max-h-[60vh] overflow-y-auto px-2" style="padding-bottom: calc(1rem + var(--safe-bottom))">
                <ul v-if="overflow.length" class="py-1">
                    <li v-for="(item, i) in overflow" :key="item.href" class="relative">
                        <button
                            type="button"
                            class="row-press flex w-full items-center gap-3 rounded-lg px-3 py-3 text-left"
                            :class="isActivePath(item.href, path) ? 'text-primary' : ''"
                            @click="go(item.href)"
                        >
                            <component :is="item.icon" class="size-5 shrink-0" />
                            <span class="text-[0.95rem] font-medium">{{ item.title }}</span>
                        </button>
                        <!-- Inset separator: starts at the label, like an iOS list. -->
                        <span v-if="i < overflow.length - 1" class="pointer-events-none absolute bottom-0 left-11 right-0 h-px bg-border" aria-hidden="true" />
                    </li>
                </ul>

                <div class="my-1 border-t border-border" />

                <ul class="py-1">
                    <li class="relative">
                        <button
                            type="button"
                            class="row-press flex w-full items-center gap-3 rounded-lg px-3 py-3 text-left"
                            @click="go('/settings/profile')"
                        >
                            <Settings class="size-5 shrink-0" />
                            <span class="text-[0.95rem] font-medium">Settings</span>
                        </button>
                        <span class="pointer-events-none absolute bottom-0 left-11 right-0 h-px bg-border" aria-hidden="true" />
                    </li>
                    <li>
                        <Link
                            method="post"
                            :href="route('logout')"
                            as="button"
                            class="row-press flex w-full items-center gap-3 rounded-lg px-3 py-3 text-left text-destructive"
                        >
                            <LogOut class="size-5 shrink-0" />
                            <span class="text-[0.95rem] font-medium">Log out</span>
                        </Link>
                    </li>
                </ul>
            </div>
        </SheetContent>
    </Sheet>
</template>
