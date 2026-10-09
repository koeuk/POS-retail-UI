<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { Moon, Sun } from 'lucide-vue-next';
import { computed } from 'vue';

withDefaults(defineProps<{ class?: string }>(), { class: '' });

const { appearance, updateAppearance } = useAppearance();

const icon = computed(() => (appearance.value === 'dark' ? Moon : Sun));
const label = computed(() => (appearance.value === 'dark' ? 'Dark' : 'Light'));

function toggle() {
    updateAppearance(appearance.value === 'dark' ? 'light' : 'dark');
}
</script>

<template>
    <button
        type="button"
        class="press flex size-10 items-center justify-center rounded-full transition-colors"
        :class="$props.class"
        :title="`Theme: ${label}. Tap to change.`"
        :aria-label="`Theme: ${label}. Tap to change.`"
        @click="toggle"
    >
        <component :is="icon" class="size-4" />
    </button>
</template>
