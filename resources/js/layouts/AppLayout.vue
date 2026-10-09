<script setup lang="ts">
import DevicePreviewSelect from '@/components/DevicePreviewSelect.vue';
import FlashToast from '@/components/FlashToast.vue';
import { isPreviewFrame, useDevicePreview } from '@/composables/useDevicePreview';
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

/*
 * Re-keying on the path recreates the wrapper on every navigation, which
 * replays the CSS entrance animation. Cheaper and steadier than a <Transition>
 * pair, and it never leaves two pages mounted at once.
 *
 * Query-string changes (search, filters, pagination) deliberately do NOT
 * re-key — re-animating the page on every keystroke would be nauseating.
 */
const page = usePage();
const pageKey = computed(() => new URL(page.url, 'http://x').pathname);

const { device, devices } = useDevicePreview();
const frameWidth = computed(() => devices[device.value].width);

/* The frame shows this same page. It knows it is the preview from its own
   window hierarchy (see isPreviewFrame), so the URL needs no flag. */
const previewUrl = computed(() => page.url);
</script>

<template>
    <!--
        Device preview. Tailwind's md:/lg: breakpoints are media queries against
        the browser window, so shrinking a div would change the size but not
        the behaviour — the tab bar and stacked layouts would never appear. An
        iframe is a real nested viewport: inside it every breakpoint fires
        exactly as on the device. The frame loads this same URL with a flag so
        the inner copy renders plain and never nests another frame.
    -->
    <div v-if="frameWidth && !isPreviewFrame" class="flex min-h-svh flex-col bg-muted/60">
        <div class="flex items-center justify-between border-b border-border bg-card px-4 py-2">
            <p class="text-xs text-muted-foreground">
                Previewing as <strong class="text-foreground">{{ devices[device].label }}</strong>
                <span class="tabular ml-1 font-mono">{{ frameWidth }}px</span>
            </p>
            <DevicePreviewSelect />
        </div>
        <div class="flex flex-1 items-start justify-center overflow-auto p-6">
            <iframe
                :key="previewUrl"
                :src="previewUrl"
                :style="{ width: `${frameWidth}px`, height: 'calc(100svh - 6.5rem)' }"
                class="box-content shrink-0 rounded-[2rem] border-[10px] border-foreground/90 bg-background shadow-2xl"
                title="Device preview"
            />
        </div>
    </div>

    <AppSidebarLayout v-else :breadcrumbs="breadcrumbs">
        <template #actions><slot name="actions" /></template>

        <div :key="pageKey" class="animate-rise">
            <slot />
        </div>
    </AppSidebarLayout>

    <FlashToast />
</template>
