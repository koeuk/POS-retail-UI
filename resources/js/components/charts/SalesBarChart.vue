<script setup lang="ts">
import { formatCurrency, USD, type CurrencyDef } from '@/composables/useCurrency';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Row {
    day: string;
    orders: number;
    sales: string;
}

const props = withDefaults(
    defineProps<{
        rows: Row[];
        currency?: CurrencyDef;
        height?: number;
    }>(),
    { currency: () => USD, height: 200 },
);

/*
 * One series, so there is no legend — the panel title names it. Colour here
 * carries no identity, only "this is the data", which is why it is the theme
 * primary rather than a categorical slot.
 */
const W = 720;
const PAD = { top: 12, right: 8, bottom: 22, left: 44 };

const values = computed(() => props.rows.map((r) => Number(r.sales)));
const peak = computed(() => Math.max(1, ...values.value));

/** Round the axis top to something a human would choose. */
const axisMax = computed(() => {
    const p = peak.value;
    const mag = 10 ** Math.floor(Math.log10(p));
    return Math.ceil(p / mag) * mag;
});

const plotW = computed(() => W - PAD.left - PAD.right);
const plotH = computed(() => props.height - PAD.top - PAD.bottom);

const slot = computed(() => plotW.value / Math.max(1, props.rows.length));
// A 2px surface gap between adjacent bars, per the mark spec.
const barW = computed(() => Math.max(2, Math.min(28, slot.value - 2)));

const yOf = (value: number) => PAD.top + plotH.value * (1 - value / axisMax.value);
const xOf = (i: number) => PAD.left + slot.value * i + (slot.value - barW.value) / 2;

/** Rounded data-end anchored to the baseline: only the top corners curve. */
function barPath(i: number, value: number): string {
    const x = xOf(i);
    const w = barW.value;
    const yTop = yOf(value);
    const yBase = PAD.top + plotH.value;
    const h = Math.max(0, yBase - yTop);
    const r = Math.min(4, w / 2, h);

    if (h <= 0) return '';

    return `M${x},${yBase} L${x},${yTop + r} Q${x},${yTop} ${x + r},${yTop} L${x + w - r},${yTop} Q${x + w},${yTop} ${x + w},${yTop + r} L${x + w},${yBase} Z`;
}

const gridLines = computed(() => [0, 0.5, 1].map((t) => ({ t, y: PAD.top + plotH.value * (1 - t), value: axisMax.value * t })));

/** Selective labels — never one per bar. First, last, and the midpoint. */
const labelIndexes = computed(() => {
    const n = props.rows.length;
    if (n <= 1) return [0];
    return [...new Set([0, Math.floor((n - 1) / 2), n - 1])];
});

const hovered = ref<number | null>(null);

/*
 * Scrolling away from a chart must dismiss its tooltip. On a phone the tap
 * that opened it is long over, and a label left floating over the next panel
 * reads as part of that panel.
 */
onMounted(() => window.addEventListener('scroll', clearHover, { passive: true, capture: true }));
onBeforeUnmount(() => window.removeEventListener('scroll', clearHover, { capture: true }));

function clearHover() {
    hovered.value = null;
}

const shortDay = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });

const money = (n: number) => formatCurrency(n, props.currency ?? USD);

const tooltip = computed(() => {
    if (hovered.value === null) return null;
    const row = props.rows[hovered.value];
    if (!row) return null;

    return {
        left: `${((xOf(hovered.value) + barW.value / 2) / W) * 100}%`,
        day: shortDay(row.day),
        sales: money(Number(row.sales)),
        orders: row.orders,
    };
});
</script>

<template>
    <figure class="relative m-0">
        <svg
            :viewBox="`0 0 ${W} ${height}`"
            class="w-full"
            :style="{ height: `${height}px` }"
            role="img"
            :aria-label="`Sales for the last ${rows.length} days`"
            @pointerleave="hovered = null"
            @pointercancel="hovered = null"
            @touchend="hovered = null"
        >
            <!-- Recessive grid: it orients, it does not compete. -->
            <g>
                <line
                    v-for="line in gridLines"
                    :key="line.t"
                    :x1="PAD.left"
                    :x2="W - PAD.right"
                    :y1="line.y"
                    :y2="line.y"
                    class="stroke-border"
                    stroke-width="1"
                />
                <text
                    v-for="line in gridLines"
                    :key="`l-${line.t}`"
                    :x="PAD.left - 8"
                    :y="line.y + 3"
                    text-anchor="end"
                    class="fill-muted-foreground text-[9px]"
                >
                    {{ Math.round(line.value) }}
                </text>
            </g>

            <!-- Bars -->
            <g>
                <path
                    v-for="(row, i) in rows"
                    :key="row.day"
                    :d="barPath(i, Number(row.sales))"
                    class="fill-primary transition-opacity duration-150"
                    :class="hovered !== null && hovered !== i ? 'opacity-35' : 'opacity-100'"
                />
            </g>

            <!--
                Invisible hit targets, wider than the marks so hovering is easy.

                pointermove, not pointerenter: the pointer stays wherever it
                was last used, so after an Inertia navigation a bar can mount
                directly underneath it and `enter` fires with nobody touching
                anything — which pinned a tooltip open over the panel heading.
                Movement is the only honest signal that someone is looking.
            -->
            <g>
                <rect
                    v-for="(row, i) in rows"
                    :key="`hit-${row.day}`"
                    :x="PAD.left + slot * i"
                    :y="PAD.top"
                    :width="slot"
                    :height="plotH"
                    fill="transparent"
                    @pointermove="hovered = i"
                />
            </g>

            <g>
                <text
                    v-for="i in labelIndexes"
                    :key="`x-${i}`"
                    :x="xOf(i) + barW / 2"
                    :y="height - 6"
                    text-anchor="middle"
                    class="fill-muted-foreground text-[9px]"
                >
                    {{ shortDay(rows[i]?.day ?? '') }}
                </text>
            </g>
        </svg>

        <div
            v-if="tooltip"
            class="pointer-events-none absolute -top-1 z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md border border-border bg-popover px-2.5 py-1.5 text-xs shadow-md"
            :style="{ left: tooltip.left }"
        >
            <p class="font-medium">{{ tooltip.day }}</p>
            <p class="tabular font-mono text-primary">{{ tooltip.sales }}</p>
            <p class="tabular font-mono text-[0.7rem] text-muted-foreground">{{ tooltip.orders }} order{{ tooltip.orders === 1 ? '' : 's' }}</p>
        </div>
    </figure>
</template>
