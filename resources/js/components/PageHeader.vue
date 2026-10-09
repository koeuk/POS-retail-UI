<script setup lang="ts">
defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <!--
        Two headers for two shapes. On a phone the screen title already sits in
        the app bar, so repeating it here would burn a third of a small screen
        restating what the user just read — the mobile header keeps only the
        description and the actions, and the actions go full-width where a
        thumb can reach them.
    -->
    <header class="animate-rise flex flex-wrap items-end justify-between gap-3 pb-4 md:gap-4 md:pb-6">
        <div class="hidden space-y-1.5 md:block">
            <!-- The slot lets a page mix tones inside the heading — the
                 Dashboard greets softly and leaves only the name bold. -->
            <h1 class="font-display text-3xl font-semibold leading-none tracking-tight">
                <slot name="title">{{ title }}</slot>
            </h1>
            <p v-if="description" class="max-w-prose text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>

        <p v-if="description" class="w-full max-w-prose text-sm leading-snug text-muted-foreground md:hidden">
            {{ description }}
        </p>

        <div class="flex w-full items-center gap-2 md:w-auto [&>*]:flex-1 md:[&>*]:flex-none">
            <slot name="actions" />
        </div>
    </header>
</template>
