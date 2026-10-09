<script setup lang="ts">
import ThemeToggle from '@/components/ThemeToggle.vue';
import { isTelegram } from '@/composables/useTelegram';
import type { BreadcrumbItemType } from '@/types';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[] }>(), {
    breadcrumbs: () => [],
});

/** The trail's last entry is where you are; anything before it is the title. */
const title = computed(() => props.breadcrumbs.at(-1)?.title ?? '');
const parent = computed(() => (props.breadcrumbs.length > 1 ? props.breadcrumbs.at(-2) : null));

/*
 * Telegram paints its own header with its own back arrow directly above this
 * bar, and useTelegram() already wires that arrow to history.back(). Drawing a
 * second chevron underneath the first is the giveaway that a page is a website
 * wearing an app's clothes, so in Telegram this one stands down.
 */
const showBack = computed(() => !!parent.value && !isTelegram());
</script>

<template>
    <header
        class="sticky top-0 z-30 flex shrink-0 items-center gap-1 border-b border-border/60 bg-background/70 px-2 backdrop-blur-xl md:hidden"
        style="padding-top: var(--safe-top)"
    >
        <div class="flex w-full items-center gap-1" :style="{ height: 'var(--appbar-h)' }">
            <Link
                v-if="showBack"
                :href="parent!.href"
                class="press touch-target -ml-1 flex size-10 items-center justify-center rounded-full text-foreground"
                :aria-label="`Back to ${parent!.title}`"
            >
                <ChevronLeft class="size-6" />
            </Link>

            <h1 class="truncate px-2 font-display text-base font-semibold tracking-tight" :class="showBack ? '' : 'pl-3'">
                {{ title }}
            </h1>

            <div class="ml-auto flex items-center gap-1 pr-1">
                <slot name="actions" />
                <ThemeToggle class="text-muted-foreground active:bg-accent" />
            </div>
        </div>
    </header>
</template>
