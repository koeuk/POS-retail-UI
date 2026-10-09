<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<SharedData>();
const appName = computed(() => page.props.name ?? 'POS Retail');
const logoUrl = computed(() => {
    const path = page.props.branding?.logo;
    return path ? `/storage/${path}` : null;
});
</script>

<template>
    <img v-if="logoUrl" :src="logoUrl" alt="" class="aspect-square size-8 shrink-0 rounded-md object-cover shadow-sm" />
    <!-- The fallback mark sits on the deep-green sidebar, so it takes the
         panel's light accent rather than --primary, which would be dark
         green on dark green. -->
    <div
        v-else
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground shadow-sm"
    >
        <svg
            viewBox="0 0 24 24"
            class="size-4"
            fill="none"
            stroke="currentColor"
            stroke-width="2.2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="M4 5h16l-1.2 9.5a2 2 0 0 1-2 1.75H7.2a2 2 0 0 1-2-1.75L4 5Z" />
            <path d="M9 20.5h.01M16 20.5h.01" />
            <path d="M8.5 9.5h7" />
        </svg>
    </div>
    <div class="ml-1.5 grid flex-1 text-left">
        <span class="truncate font-display text-[0.95rem] font-semibold leading-tight tracking-tight">
            {{ appName }}
        </span>
        <span class="truncate font-mono text-[0.6rem] uppercase tracking-[0.18em] text-muted-foreground"> Retail </span>
    </div>
</template>
