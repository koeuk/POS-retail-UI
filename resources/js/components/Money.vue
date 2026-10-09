<script setup lang="ts">
import { useCurrency } from '@/composables/useCurrency';

const props = withDefaults(
    defineProps<{
        /** A stored USD amount. Decimals arrive from Laravel as strings to avoid float drift. */
        value: string | number | null | undefined;
        /** Dim the symbol so columns of figures read as one block. */
        muted?: boolean;
    }>(),
    { muted: true },
);

const { currency, money } = useCurrency();

/* Split the symbol off so it can be dimmed independently of the digits. */
const digits = () => money(props.value).slice(currency.value.symbol.length);
</script>

<template>
    <span class="tabular whitespace-nowrap font-mono text-[0.9em]">
        <span :class="muted ? 'text-muted-foreground' : ''">{{ currency.symbol }}</span
        >{{ digits() }}
    </span>
</template>
