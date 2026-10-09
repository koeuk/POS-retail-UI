<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <main
        :class="
            cn(
                // text-foreground is load-bearing: SidebarProvider puts
                // text-sidebar-foreground on the whole app wrapper, so the
                // content area must claim its own colour back or every page
                // inherits the sidebar's (light-on-dark) text.
                'relative flex min-h-svh flex-1 flex-col bg-background text-foreground',
                'peer-data-[variant=inset]:min-h-[calc(100svh-theme(spacing.4))] md:peer-data-[variant=inset]:m-2 md:peer-data-[state=collapsed]:peer-data-[variant=inset]:ml-2 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-xl md:peer-data-[variant=inset]:shadow',
                props.class,
            )
        "
    >
        <slot />
    </main>
</template>
