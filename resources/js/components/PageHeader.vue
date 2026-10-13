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
        restating what the user just read. The mobile header hides the
        description, keeping only actions (if present) so actions go full-width
        where a thumb can reach them. If there are no actions, the header is
        hidden on mobile to preserve screen estate.
    -->
    <header
        class="animate-rise flex flex-wrap items-end justify-between gap-3 md:gap-4 md:pb-6"
        :class="$slots.actions ? 'pb-4' : 'hidden md:flex'"
    >
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

        <div v-if="$slots.actions" class="flex w-full items-center gap-2 md:w-auto [&>*]:flex-1 md:[&>*]:flex-none">
            <slot name="actions" />
        </div>
    </header>
</template>
