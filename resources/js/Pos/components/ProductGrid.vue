<script setup lang="ts">
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { CurrencyDef } from '@/composables/useCurrency';
import { useCart } from '@/Pos/composables/useCart';
import { formatMoney } from '@/Pos/lib/money';
import { imageSrc } from '@/lib/utils';
import type { PosCategory, PosProduct } from '@/Pos/types';
import { ChevronLeft, ChevronRight, Layers, PackageOpen, Search } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    products: PosProduct[];
    categories: PosCategory[];
    currency: CurrencyDef;
}>();

const emit = defineEmits<{ add: [product: PosProduct] }>();

const search = ref('');
const activeCategory = ref<number | null>(null);

const matches = (p: PosProduct, q: string) => p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q) || (p.barcode ?? '').includes(q);

/*
 * The grid shows base products only. A beer sold as a case, a six-pack and a
 * single is one tile with a chooser, not three tiles the cashier has to tell
 * apart mid-queue.
 *
 * Packs are still searchable and still scannable: typing a case's SKU, or
 * scanning its carton, surfaces the tile it belongs to.
 */
const packsByParent = computed(() => {
    const map = new Map<number, PosProduct[]>();

    for (const p of props.products) {
        if (p.parent_product_id === null) continue;
        const list = map.get(p.parent_product_id) ?? [];
        list.push(p);
        map.set(p.parent_product_id, list);
    }

    for (const list of map.values()) list.sort((a, b) => a.units_per_pack - b.units_per_pack);

    return map;
});

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.products.filter((p) => {
        if (p.parent_product_id !== null) return false;
        if (activeCategory.value !== null && p.category_id !== activeCategory.value) return false;
        if (!q) return true;

        return matches(p, q) || (packsByParent.value.get(p.id) ?? []).some((pack) => matches(pack, q));
    });
});

/** Every way this product can be sold, single first. */
const sellableAs = (product: PosProduct) => [product, ...(packsByParent.value.get(product.id) ?? [])];

/**
 * The dearest way to buy it, when there is more than one. The tile shows the
 * single price and this, so a cashier reads the span at a glance instead of
 * having to open the chooser to find out a case exists.
 */
function dearest(product: PosProduct): string | null {
    const options = sellableAs(product);
    if (options.length === 1) return null;

    const top = options.reduce((a, b) => (Number(b.sell_price) > Number(a.sell_price) ? b : a));

    return Number(top.sell_price) > Number(product.sell_price) ? top.sell_price : null;
}

/*
 * The tile itself always sells one single — the overwhelming case at a till,
 * so it must stay one tap. Every other way to buy lives behind the "See more"
 * button, which opens a dialog (a bottom sheet on a phone) rather than the old
 * overlay squeezed inside a 180px tile, where long Khmer pack names had no
 * room to breathe.
 */
const viewing = ref<PosProduct | null>(null);

function tap(product: PosProduct) {
    emit('add', product);
}

function choose(option: PosProduct) {
    viewing.value = null;
    emit('add', option);
}

/** Only categories that actually have something in the current filter. */
const usableCategories = computed(() => {
    const ids = new Set(props.products.map((p) => p.category_id));
    return props.categories.filter((c) => ids.has(c.id));
});

function stockTone(product: PosProduct): string {
    if (!product.track_stock) return 'text-muted-foreground';
    if (product.stock_qty <= 0) return 'text-destructive';
    if (product.stock_qty <= 5) return 'text-primary';
    return 'text-muted-foreground';
}

/*
 * A tile goes dark not only at zero stock, but as soon as the cart already
 * holds everything the shelf has — otherwise a cashier could keep tapping a
 * 15-pc item up to 34 and only find out at the stock report.
 */
const cart = useCart();
const inCart = (productId: number) => cart.lines.find((l) => l.productId === productId)?.qty ?? 0;

const outOfStock = (product: PosProduct) => product.track_stock && product.stock_qty - inCart(product.id) <= 0;

/** Nothing on this tile can be sold — not the single, not any pack. */
const fullySoldOut = (product: PosProduct) => sellableAs(product).every(outOfStock);

defineExpose({ focusSearch: () => document.getElementById('pos-search')?.focus() });

/*
 * The category rail as a carousel: chevrons page it, each shown only while
 * that direction has more chips. The scrollbar is hidden; touch swipe stays.
 */
const chipRail = ref<HTMLElement | null>(null);
const canScrollLeft = ref(false);
const canScrollRight = ref(false);

function updateChipArrows() {
    const el = chipRail.value;
    if (!el) return;
    canScrollLeft.value = el.scrollLeft > 4;
    canScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4;
}

function scrollChips(direction: 1 | -1) {
    const el = chipRail.value;
    if (!el) return;
    el.scrollBy({ left: direction * Math.round(el.clientWidth * 0.7), behavior: 'smooth' });
}

onMounted(() => {
    void nextTick(updateChipArrows);
    window.addEventListener('resize', updateChipArrows);
});

onBeforeUnmount(() => window.removeEventListener('resize', updateChipArrows));
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <!-- Filters -->
        <div class="shrink-0 space-y-2 border-b border-border p-3">
            <div class="relative">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-muted-foreground" />
                <input
                    id="pos-search"
                    v-model="search"
                    type="search"
                    placeholder="Search or scan…"
                    autocomplete="off"
                    class="h-12 w-full rounded-xl border border-input bg-background pl-11 pr-3 text-base outline-none ring-offset-background focus-visible:ring-2 focus-visible:ring-ring"
                />
            </div>

            <div class="relative">
                <!-- Paging chevrons: only rendered while that direction has more chips. -->
                <button
                    v-if="canScrollLeft"
                    type="button"
                    class="press absolute -left-1 top-1/2 z-10 flex size-8 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-md transition-colors hover:text-foreground"
                    aria-label="Scroll categories left"
                    @click="scrollChips(-1)"
                >
                    <ChevronLeft class="size-4" />
                </button>
                <button
                    v-if="canScrollRight"
                    type="button"
                    class="press absolute -right-1 top-1/2 z-10 flex size-8 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-md transition-colors hover:text-foreground"
                    aria-label="Scroll categories right"
                    @click="scrollChips(1)"
                >
                    <ChevronRight class="size-4" />
                </button>

                <!-- Soft edges hint that the rail continues past the fold. -->
                <div v-if="canScrollLeft" class="pointer-events-none absolute inset-y-0 left-0 z-[5] w-10 bg-gradient-to-r from-background to-transparent" />
                <div v-if="canScrollRight" class="pointer-events-none absolute inset-y-0 right-0 z-[5] w-10 bg-gradient-to-l from-background to-transparent" />

                <div ref="chipRail" class="scrollbar-none flex gap-1.5 overflow-x-auto" @scroll.passive="updateChipArrows">
                    <button
                        type="button"
                        class="press h-9 shrink-0 rounded-full border px-4 text-sm font-medium"
                        :class="activeCategory === null ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground'"
                        @click="activeCategory = null"
                    >
                        All
                    </button>
                    <button
                        v-for="c in usableCategories"
                        :key="c.id"
                        type="button"
                        class="press h-9 shrink-0 rounded-full border px-4 text-sm font-medium"
                        :class="activeCategory === c.id ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground'"
                        @click="activeCategory = c.id"
                    >
                        {{ c.name }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Grid. No entrance animation: this re-renders on every keystroke,
             and a cashier tapping 60 items a minute needs it to feel instant. -->
        <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <div v-if="visible.length" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                <div v-for="product in visible" :key="product.id" class="relative">
                    <button
                        type="button"
                        class="flex h-full w-full flex-col overflow-hidden rounded-2xl border border-border bg-card text-left transition-colors"
                        :class="outOfStock(product) ? 'cursor-not-allowed opacity-60' : 'press hover:border-primary/50 active:border-primary'"
                        :disabled="outOfStock(product)"
                        @click="tap(product)"
                    >
                        <div class="relative flex aspect-square w-full items-center justify-center overflow-hidden bg-muted/40">
                            <img
                                v-if="product.image"
                                :src="imageSrc(product.image)"
                                :alt="product.name"
                                loading="lazy"
                                class="size-full object-cover"
                                :class="outOfStock(product) && 'grayscale'"
                            />
                            <PackageOpen v-else class="size-7 text-muted-foreground/50" />

                            <span
                                v-if="outOfStock(product)"
                                class="absolute inset-x-0 bottom-0 bg-destructive/90 py-1 text-center text-[0.7rem] font-semibold uppercase tracking-wide text-white"
                            >
                                Out of stock
                            </span>

                            <!-- Says at a glance that this tile hides more than one price. -->
                            <span
                                v-if="sellableAs(product).length > 1"
                                class="absolute right-1.5 top-1.5 flex items-center gap-1 rounded-full bg-card/90 px-1.5 py-0.5 font-mono text-[0.65rem] font-medium text-muted-foreground backdrop-blur"
                            >
                                <Layers class="size-3" />
                                {{ sellableAs(product).length }}
                            </span>
                        </div>

                        <div class="flex flex-1 flex-col gap-0.5 p-2.5">
                            <p class="line-clamp-2 text-sm font-medium leading-snug">{{ product.name }}</p>
                            <p class="tabular mt-auto font-mono text-base font-semibold text-primary">
                                {{ formatMoney(Number(product.sell_price), currency) }}
                                <span v-if="dearest(product)" class="text-xs font-normal text-muted-foreground">
                                    – {{ formatMoney(Number(dearest(product)), currency) }}
                                </span>
                            </p>
                            <p class="tabular font-mono text-[0.7rem]" :class="stockTone(product)">
                                {{ product.track_stock ? `${product.stock_qty} ${product.unit}` : product.unit }}
                            </p>
                        </div>
                    </button>

                    <!--
                        Not nested in the tile button — a button inside a button
                        is invalid HTML and the browsers disagree on which one
                        the tap belongs to. A sibling, absolutely placed over
                        the price corner.
                    -->
                    <button
                        v-if="sellableAs(product).length > 1 && !fullySoldOut(product)"
                        type="button"
                        class="press absolute bottom-2 right-2 z-10 flex items-center gap-0.5 rounded-full border border-border bg-card/95 py-1 pl-2 pr-1 text-[0.7rem] font-medium text-muted-foreground shadow-sm backdrop-blur transition-colors hover:border-primary/50 hover:text-foreground"
                        :aria-label="`See every price for ${product.name}`"
                        @click.stop="viewing = product"
                    >
                        See more
                        <ChevronRight class="size-3.5" />
                    </button>
                </div>
            </div>

            <div v-else class="flex h-full flex-col items-center justify-center gap-2 text-center">
                <PackageOpen class="size-8 text-muted-foreground/40" />
                <p class="font-medium">Nothing matches</p>
                <p class="text-sm text-muted-foreground">Try another word, or clear the filter.</p>
            </div>
        </div>

        <!-- Every way to buy, as a real dialog — a bottom sheet on a phone. -->
        <Dialog :open="!!viewing" @update:open="(v) => !v && (viewing = null)">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>{{ viewing?.name }}</DialogTitle>
                    <DialogDescription>Tap a size to add it to the cart.</DialogDescription>
                </DialogHeader>

                <ul v-if="viewing" class="-mx-1 divide-y divide-border">
                    <li v-for="option in sellableAs(viewing)" :key="option.id">
                        <button
                            type="button"
                            class="flex w-full items-baseline justify-between gap-3 rounded-lg px-3 py-3 text-left"
                            :class="outOfStock(option) ? 'cursor-not-allowed opacity-50' : 'row-press'"
                            :disabled="outOfStock(option)"
                            @click="choose(option)"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium leading-snug">
                                    {{ option.id === viewing.id ? `1 ${viewing.unit}` : option.name }}
                                </span>
                                <span v-if="option.id !== viewing.id" class="tabular font-mono text-xs text-muted-foreground">
                                    ×{{ option.units_per_pack }} {{ viewing.unit }}
                                </span>
                                <span v-if="outOfStock(option)" class="block text-xs font-medium text-destructive">Out of stock</span>
                            </span>
                            <span class="tabular shrink-0 font-mono text-base font-semibold text-primary">
                                {{ formatMoney(Number(option.sell_price), currency) }}
                            </span>
                        </button>
                    </li>
                </ul>
            </DialogContent>
        </Dialog>
    </div>
</template>
