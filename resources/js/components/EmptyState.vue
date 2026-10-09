<script setup lang="ts">
import type { LucideIcon } from 'lucide-vue-next';
import { PackageOpen } from 'lucide-vue-next';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        icon?: LucideIcon;
    }>(),
    { icon: () => PackageOpen },
);
</script>

<template>
    <div class="animate-scale flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
        <div class="flex size-12 items-center justify-center rounded-full border border-dashed border-border bg-muted/40 text-muted-foreground">
            <component :is="icon" class="size-5" />
        </div>
        <div class="space-y-1">
            <p class="font-display text-base font-semibold">{{ title }}</p>
            <p v-if="description" class="max-w-sm text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <div class="mt-1">
            <slot />
        </div>
    </div>
</template>
