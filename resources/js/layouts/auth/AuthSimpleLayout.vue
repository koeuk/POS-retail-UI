<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage<SharedData>();
const logoUrl = computed(() => {
    const path = page.props.branding?.logo;
    return path ? `/storage/${path}` : null;
});
</script>

<template>
    <div class="auth-glow relative flex min-h-svh flex-col items-center justify-center gap-6 overflow-hidden bg-background p-6 md:p-10">
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-6">
                <Link :href="route('home')" class="press flex flex-col items-center gap-2 font-medium">
                    <div class="shadow-soft flex size-14 items-center justify-center overflow-hidden rounded-2xl">
                        <img v-if="logoUrl" :src="logoUrl" alt="" class="size-full object-cover" />
                        <AppLogoIcon v-else class="size-10 fill-current text-[var(--foreground)] dark:text-white" />
                    </div>
                    <span class="sr-only">{{ title }}</span>
                </Link>

                <div class="animate-rise rounded-3xl border border-border/70 bg-card p-6 sm:p-8">
                    <div class="mb-6 space-y-1.5 text-center">
                        <h1 class="font-display text-2xl font-semibold tracking-tight">{{ title }}</h1>
                        <p class="text-sm text-muted-foreground">{{ description }}</p>
                    </div>
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
