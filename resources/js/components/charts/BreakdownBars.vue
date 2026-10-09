<script setup lang="ts">
import { formatCurrency, USD, type CurrencyDef } from '@/composables/useCurrency';
import { computed } from 'vue';

interface Row {
    label: string;
    value: number;
    /** Secondary figure shown as recessive text, e.g. a transaction count. */
    meta?: string;
    /**
     * Fixed categorical slot (0-3) for this entity. Supply it whenever rows
     * can be reordered — colour must follow the thing, never its rank, or a
     * change in takings repaints every bar and the reader has to relearn
     * the legend.
     */
    slot?: number;
}

const props = withDefaults(
    defineProps<{
        rows: Row[];
        currency?: CurrencyDef;
        /** Categorical when each row is a different thing; single-hue otherwise. */
        categorical?: boolean;
    }>(),
    { currency: () => USD, categorical: false },
);

/*
 * Horizontal bars, because the labels are words. Every row carries its name
 * and its value as text — two of the four validated series colours fall below
 * 3:1 against the light surface, and the validator's contrast warning is
 * discharged by visible labels, not ignored.
 */
const total = computed(() => props.rows.reduce((sum, r) => sum + r.value, 0));
const peak = computed(() => Math.max(1, ...props.rows.map((r) => r.value)));

const width = (value: number) => `${Math.max(1.5, (value / peak.value) * 100)}%`;

/** Fixed slot order, never cycled — colour follows the entity, not its rank. */
const seriesVar = (row: Row, index: number) => `var(--series-${((row.slot ?? index) % 4) + 1})`;

const share = (value: number) => (total.value > 0 ? Math.round((value / total.value) * 100) : 0);

const money = (n: number) => formatCurrency(n, props.currency ?? USD);
</script>

<template>
    <ul v-if="rows.length" class="space-y-2.5">
        <li v-for="(row, i) in rows" :key="row.label" class="space-y-1">
            <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="flex min-w-0 items-center gap-2">
                    <span class="size-2.5 shrink-0 rounded-[3px]" :style="{ background: categorical ? seriesVar(row, i) : 'hsl(var(--primary))' }" />
                    <!-- No capitalize transform: labels arrive presentation-ready,
                         and it would mangle product names and initialisms. -->
                    <span class="truncate">{{ row.label }}</span>
                </span>
                <span class="tabular shrink-0 font-mono font-medium">{{ money(row.value) }}</span>
            </div>

            <div class="flex items-center gap-2">
                <!-- 4px rounded end, anchored to the left baseline. -->
                <div class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full transition-[width] duration-300 ease-out-quint"
                        :style="{
                            width: width(row.value),
                            background: categorical ? seriesVar(row, i) : 'hsl(var(--primary))',
                        }"
                    />
                </div>
                <span class="tabular w-16 shrink-0 text-right font-mono text-[0.7rem] text-muted-foreground">
                    {{ share(row.value) }}%<template v-if="row.meta"> · {{ row.meta }}</template>
                </span>
            </div>
        </li>
    </ul>

    <p v-else class="py-6 text-center text-sm text-muted-foreground">Nothing in this range.</p>
</template>
