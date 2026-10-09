<script setup lang="ts">
import { CloudOff, LoaderCircle, LockKeyhole, RefreshCw, TriangleAlert, Wifi } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        online: boolean;
        syncing: boolean;
        pending: number;
        authExpired: boolean;
        /** Sales the server refused outright — retrying as-is cannot fix them. */
        rejected?: number;
    }>(),
    { rejected: 0 },
);

const emit = defineEmits<{ retry: [] }>();

/**
 * Five states, in order of how much they should worry the cashier:
 *
 *  - locked   session gone; selling still works, syncing does not
 *  - refused  the server read a sale and rejected it; a human must look
 *  - offline  no server; sales are queueing, which is fine
 *  - syncing  draining the queue right now
 *  - online   nothing outstanding
 *
 * "refused" outranks "offline" because it is the only one that will not clear
 * itself: an offline till syncs the moment the signal returns, a refused sale
 * waits for someone to notice it.
 */
const state = computed(() => {
    if (props.authExpired) return 'locked';
    if (props.rejected > 0) return 'refused';
    if (!props.online) return 'offline';
    if (props.syncing) return 'syncing';
    return 'online';
});

const label = computed(() => {
    switch (state.value) {
        case 'locked':
            return 'Sign in to sync';
        case 'refused':
            return `${props.rejected} sale(s) refused`;
        case 'offline':
            return props.pending > 0 ? `Offline · ${props.pending} queued` : 'Offline';
        case 'syncing':
            return 'Syncing…';
        default:
            return props.pending > 0 ? `${props.pending} queued` : 'Online';
    }
});

const tone = computed(() => {
    switch (state.value) {
        case 'locked':
        case 'refused':
            return 'border-destructive/50 bg-destructive/10 text-destructive';
        case 'offline':
            return 'border-primary/50 bg-primary/10 text-primary';
        default:
            return 'border-border text-muted-foreground';
    }
});

const icon = computed(() => {
    switch (state.value) {
        case 'locked':
            return LockKeyhole;
        case 'refused':
            return TriangleAlert;
        case 'offline':
            return CloudOff;
        case 'syncing':
            return LoaderCircle;
        default:
            return Wifi;
    }
});
</script>

<template>
    <button
        type="button"
        class="press flex h-9 items-center gap-2 rounded-full border px-3 text-xs font-medium transition-colors"
        :class="tone"
        :title="
            authExpired
                ? 'Your session expired. Queued sales are safe.'
                : state === 'refused'
                  ? 'The server rejected these sales — they are still saved on this device. Tap to try again.'
                  : label
        "
        @click="emit('retry')"
    >
        <component :is="icon" class="size-3.5" :class="syncing ? 'animate-spin' : ''" />
        <span>{{ label }}</span>

        <!-- The count is the number the cashier actually cares about, so it
             gets its own chip rather than being buried in the label. -->
        <span
            v-if="pending > 0 && state !== 'offline' && state !== 'refused'"
            class="tabular rounded-full bg-primary px-1.5 py-0.5 font-mono text-[0.65rem] text-primary-foreground"
        >
            {{ pending }}
        </span>

        <RefreshCw v-if="state === 'online' && pending > 0" class="size-3" />
    </button>
</template>
