<script setup lang="ts">
import type { LucideIcon } from 'lucide-vue-next';
import { TrendingDown, TrendingUp } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    label: string;
    value: string;
    icon?: LucideIcon;
    /** Prior period, for the change indicator. Omit to hide it. */
    previous?: string | number | null;
    /** Rendered under the value when there is no comparison to make. */
    hint?: string;
    tone?: 'default' | 'warning';
    /*
     * Which of the five chart accents tints the icon chip. A row of tiles
     * reads as separate figures when each carries its own colour; one shared
     * wash turns them into wallpaper. `warning` always wins — a tile shouting
     * about oversold stock must not be quietly recoloured.
     */
    accent?: 1 | 2 | 3 | 4 | 5;
}>();

const ACCENTS: Record<number, string> = {
    1: 'bg-chart-1/10 text-chart-1',
    2: 'bg-chart-2/15 text-chart-2',
    3: 'bg-chart-3/10 text-chart-3',
    4: 'bg-chart-4/15 text-chart-4',
    5: 'bg-chart-5/10 text-chart-5',
};

const chip = computed(() => (props.tone === 'warning' ? 'bg-destructive/10 text-destructive' : (ACCENTS[props.accent ?? 1] ?? ACCENTS[1])));

/*
 * A single headline number is a stat tile, not a chart — there is nothing to
 * compare within it, so a plot would be decoration.
 */
const delta = computed(() => {
    if (props.previous === null || props.previous === undefined) return null;

    const now = Number(props.value.replace(/[^0-9.-]/g, ''));
    const before = Number(props.previous);

    if (!Number.isFinite(now) || !Number.isFinite(before) || before === 0) return null;

    return ((now - before) / before) * 100;
});

/*
 * The number rolls to its value instead of appearing — on mount, and again
 * whenever the value changes under the reader (recording a debt payment
 * glides "Still owed" down to the new figure). The currency symbol and
 * grouping are kept by re-formatting each frame, so "៛165,500" counts in
 * riel, not in raw digits. Anything unparseable, and anyone who asked the
 * OS for less motion, simply gets the value as-is.
 */
const parsed = (raw: string) => {
    const m = /^([^0-9-]*)(-?[\d,]+(?:\.(\d+))?)(.*)$/.exec(raw.trim());
    if (!m) return null;

    const target = Number(m[2].replace(/,/g, ''));
    if (!Number.isFinite(target)) return null;

    return { prefix: m[1], target, decimals: m[3]?.length ?? 0, suffix: m[4] };
};

const stillness = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const shown = ref(props.value);
let frame = 0;
let current = 0;

function rollTo(raw: string) {
    cancelAnimationFrame(frame);

    const part = parsed(raw);
    if (!part || stillness()) {
        shown.value = raw;
        current = part?.target ?? 0;
        return;
    }

    const from = current;
    const start = performance.now();
    const duration = 700;

    const tick = (now: number) => {
        const t = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - t, 4); // ease-out-quart, settles gently
        current = from + (part.target - from) * eased;

        shown.value =
            t >= 1
                ? raw // the exact server string, always, at the end
                : part.prefix +
                  current.toLocaleString('en-US', { minimumFractionDigits: part.decimals, maximumFractionDigits: part.decimals }) +
                  part.suffix;

        if (t < 1) frame = requestAnimationFrame(tick);
    };

    frame = requestAnimationFrame(tick);
}

onMounted(() => rollTo(props.value));
watch(
    () => props.value,
    (v) => rollTo(v),
);
onBeforeUnmount(() => cancelAnimationFrame(frame));
</script>

<template>
    <article
        class="lift relative overflow-hidden rounded-2xl border bg-card p-4"
        :class="tone === 'warning' ? 'border-destructive/40' : 'border-border'"
    >
        <div class="relative flex items-start justify-between gap-2">
            <p class="font-mono text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">
                {{ label }}
            </p>
            <span v-if="icon" class="grid size-8 shrink-0 place-items-center rounded-lg transition-transform duration-300 ease-out" :class="chip">
                <component :is="icon" class="size-4" />
            </span>
        </div>

        <p class="tabular relative mt-1 font-mono text-2xl font-bold leading-none" :class="tone === 'warning' ? 'text-destructive' : ''">
            {{ shown }}
        </p>

        <p
            v-if="delta !== null"
            class="relative mt-1.5 flex items-center gap-1 text-xs"
            :class="delta >= 0 ? 'text-primary' : 'text-muted-foreground'"
        >
            <component :is="delta >= 0 ? TrendingUp : TrendingDown" class="size-3" />
            <span class="tabular font-mono">{{ Math.abs(delta).toFixed(0) }}%</span>
            <span class="text-muted-foreground">vs yesterday</span>
        </p>
        <p v-else-if="hint" class="relative mt-1.5 text-xs text-muted-foreground">{{ hint }}</p>
    </article>
</template>
