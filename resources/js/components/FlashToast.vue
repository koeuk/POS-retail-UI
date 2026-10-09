<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, X } from 'lucide-vue-next';
import { onUnmounted, ref, watch } from 'vue';

const page = usePage<SharedData>();

type Toast = { id: number; message: string; kind: 'success' | 'error' };

const toasts = ref<Toast[]>([]);
const timers = new Map<number, ReturnType<typeof setTimeout>>();
let seq = 0;

function push(message: string, kind: Toast['kind']) {
    const id = ++seq;
    toasts.value.push({ id, message, kind });
    timers.set(
        id,
        setTimeout(() => dismiss(id), 4200),
    );
}

function dismiss(id: number) {
    const timer = timers.get(id);
    if (timer) {
        clearTimeout(timer);
        timers.delete(id);
    }
    toasts.value = toasts.value.filter((t) => t.id !== id);
}

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) push(flash.success, 'success');
        if (flash?.error) push(flash.error, 'error');
    },
    { immediate: true, deep: true },
);

onUnmounted(() => {
    timers.forEach(clearTimeout);
    timers.clear();
});
</script>

<template>
    <div
        class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2"
        role="status"
        aria-live="polite"
    >
        <TransitionGroup
            enter-from-class="opacity-0 translate-y-2"
            enter-active-class="transition duration-200 ease-out-quint"
            leave-to-class="opacity-0 translate-y-1"
            leave-active-class="transition duration-150 ease-out-quint absolute"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex items-start gap-2.5 rounded-lg border bg-card px-3.5 py-3 shadow-lg"
                :class="toast.kind === 'error' ? 'border-destructive/40' : 'border-border'"
            >
                <component
                    :is="toast.kind === 'error' ? CircleAlert : CircleCheck"
                    class="mt-0.5 size-4 shrink-0"
                    :class="toast.kind === 'error' ? 'text-destructive' : 'text-primary'"
                />
                <p class="flex-1 text-sm leading-snug">{{ toast.message }}</p>
                <button
                    type="button"
                    class="press rounded p-0.5 text-muted-foreground transition-colors hover:text-foreground"
                    aria-label="Dismiss"
                    @click="dismiss(toast.id)"
                >
                    <X class="size-3.5" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
