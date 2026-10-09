<script setup lang="ts">
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Input } from '@/components/ui/input';
import { formatCurrency, type CurrencyDef } from '@/composables/useCurrency';
import { imageSrc } from '@/lib/utils';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ChevronLeft, ChevronRight, Eye, Search, UtensilsCrossed } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

interface MenuPack {
    id: number;
    name: string;
    units: number;
    price: number;
    sold_out: boolean;
}

interface MenuProduct {
    id: number;
    name: string;
    packs: MenuPack[];
    description: string | null;
    image: string | null;
    unit: string;
    category_id: number;
    category_name: string | null;
    price: number;
    sold_out: boolean;
}

const props = defineProps<{
    products: MenuProduct[];
    categories: { id: number; name: string }[];
    filters: { search: string; category: number | null };
    shop: { name: string; footer: string | null; currency: CurrencyDef };
}>();

/*
 * Filtering happens client-side. A menu is a small, fully-loaded list, so
 * a round-trip per keystroke would only make it feel slower.
 */
const search = ref(props.filters.search ?? '');
const activeCategory = ref<number | null>(props.filters.category);

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.products.filter((p) => {
        if (activeCategory.value !== null && p.category_id !== activeCategory.value) {
            return false;
        }
        if (!q) return true;
        return p.name.toLowerCase().includes(q) || (p.description ?? '').toLowerCase().includes(q);
    });
});

/** Grouped so the page reads like a printed menu rather than a search result. */
const grouped = computed(() => {
    const groups = new Map<number, { name: string; items: MenuProduct[] }>();

    for (const product of visible.value) {
        if (!groups.has(product.category_id)) {
            groups.set(product.category_id, {
                name: product.category_name ?? 'Other',
                items: [],
            });
        }
        groups.get(product.category_id)!.items.push(product);
    }

    return [...groups.values()].sort((a, b) => a.name.localeCompare(b.name));
});

const money = (value: number) => formatCurrency(value, props.shop.currency);

const year = new Date().getFullYear();

/*
 * This is the one route in the app with no auth — a customer can open it from
 * a QR code. The way back into the admin therefore only exists when somebody
 * is actually signed in; showing a customer a door to the dashboard would be
 * both confusing and an invitation.
 */
const page = usePage<SharedData>();
const isStaff = computed(() => !!page.props.auth?.user);

/*
 * The category rail as a carousel: chevrons page it left and right, and each
 * one only shows while there is somewhere left to go in its direction. The
 * native scrollbar is hidden — swiping still works on touch.
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
    <Head :title="`Menu — ${shop.name}`" />

    <div class="min-h-dvh bg-background text-foreground">
        <!--
            The masthead is the shop's name board. Painting it in the brand
            green makes it read as one, and gives the sticky filter bar below
            an edge to sit against instead of both dissolving into the page.

            Colours come from the --brand pair, not --primary: painted signage
            should not lighten just because the screen went dark.
        -->
        <header class="surface-brand relative text-brand-foreground">
            <Link
                v-if="isStaff"
                :href="route('dashboard')"
                class="press absolute left-4 top-4 flex h-10 items-center gap-2 rounded-full border border-brand-foreground/25 bg-brand-foreground/10 px-3 text-sm font-medium text-brand-foreground/90 transition-colors hover:bg-brand-foreground/20 hover:text-brand-foreground md:left-6 md:top-6"
            >
                <ArrowLeft class="size-4" />
                <span class="hidden sm:inline">Dashboard</span>
            </Link>

            <!-- Everyone gets this one: which theme a screen is comfortable in
                 is the viewer's business, not the shop's. -->
            <ThemeToggle
                class="absolute right-4 top-4 border border-brand-foreground/25 bg-brand-foreground/10 text-brand-foreground/90 hover:bg-brand-foreground/20 hover:text-brand-foreground md:right-6 md:top-6"
            />

            <div class="mx-auto max-w-5xl px-5 py-12 text-center md:py-16">
                <!--
                    Opacity rather than a second colour: one ink, three weights.
                    Held at /85 because anything lighter drops this 11px line
                    below 4.5:1 against the green — the faded look is not worth
                    small text nobody can read in daylight.
                -->
                <p class="animate-fade font-mono text-[0.7rem] uppercase tracking-[0.28em] text-brand-foreground/85">Our menu</p>
                <h1 class="animate-rise mt-3 font-display text-4xl font-bold tracking-tight md:text-6xl" style="animation-delay: 60ms">
                    {{ shop.name }}
                </h1>
                <p class="animate-rise mx-auto mt-3 max-w-md text-sm text-brand-foreground/85" style="animation-delay: 120ms">
                    Everything we stock, with current prices.
                </p>
            </div>
        </header>

        <!-- Sticky filter bar -->
        <div class="sticky top-0 z-20 border-b border-border bg-card/95 shadow-sm backdrop-blur">
            <div class="mx-auto max-w-5xl px-5 py-3">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="search" type="search" placeholder="Search the menu…" class="pl-9" aria-label="Search the menu" />
                </div>

                <div v-if="categories.length" class="relative mt-3">
                    <!-- Paging chevrons: only rendered while that direction has more chips. -->
                    <button
                        v-if="canScrollLeft"
                        type="button"
                        class="press absolute -left-2 top-1/2 z-10 flex size-8 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-md transition-colors hover:text-foreground"
                        aria-label="Scroll categories left"
                        @click="scrollChips(-1)"
                    >
                        <ChevronLeft class="size-4" />
                    </button>
                    <button
                        v-if="canScrollRight"
                        type="button"
                        class="press absolute -right-2 top-1/2 z-10 flex size-8 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-card text-muted-foreground shadow-md transition-colors hover:text-foreground"
                        aria-label="Scroll categories right"
                        @click="scrollChips(1)"
                    >
                        <ChevronRight class="size-4" />
                    </button>

                    <!-- Soft edges hint that the rail continues past the fold. -->
                    <div
                        v-if="canScrollLeft"
                        class="pointer-events-none absolute inset-y-0 left-0 z-[5] w-10 bg-gradient-to-r from-card to-transparent"
                    />
                    <div
                        v-if="canScrollRight"
                        class="pointer-events-none absolute inset-y-0 right-0 z-[5] w-10 bg-gradient-to-l from-card to-transparent"
                    />

                    <nav ref="chipRail" class="scrollbar-none flex gap-1.5 overflow-x-auto" @scroll.passive="updateChipArrows">
                        <button
                            type="button"
                            class="press shrink-0 rounded-full border px-3.5 py-1.5 text-xs font-medium transition-colors"
                            :class="
                                activeCategory === null
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                            "
                            @click="activeCategory = null"
                        >
                            All
                        </button>
                        <button
                            v-for="c in categories"
                            :key="c.id"
                            type="button"
                            class="press shrink-0 rounded-full border px-3.5 py-1.5 text-xs font-medium transition-colors"
                            :class="
                                activeCategory === c.id
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                            "
                            @click="activeCategory = c.id"
                        >
                            {{ c.name }}
                        </button>
                    </nav>
                </div>
            </div>
        </div>

        <!-- Menu -->
        <main class="mx-auto max-w-5xl px-5 py-10">
            <section v-for="group in grouped" :key="group.name" class="mb-12 last:mb-0">
                <div class="mb-5 flex items-baseline gap-3">
                    <h2 class="font-display text-2xl font-semibold tracking-tight">
                        {{ group.name }}
                    </h2>
                    <span class="h-px flex-1 bg-border" />
                    <span class="tabular font-mono text-xs text-muted-foreground">
                        {{ group.items.length }}
                    </span>
                </div>

                <!--
                    Two cards per row on a phone. The photo leads: a customer
                    scanning a menu recognises the product by sight long before
                    they read the name, and the old side-by-side row could only
                    afford a 64px thumbnail.
                -->
                <ul class="stagger grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <li
                        v-for="item in group.items"
                        :key="item.id"
                        class="flex flex-col overflow-hidden rounded-xl border border-border bg-card"
                        :class="item.sold_out ? 'opacity-60' : 'lift'"
                    >
                        <div class="relative flex aspect-square w-full items-center justify-center overflow-hidden bg-muted/40">
                            <img
                                v-if="item.image"
                                :src="imageSrc(item.image)"
                                :alt="item.name"
                                loading="lazy"
                                class="size-full object-cover"
                                :class="item.sold_out && 'grayscale'"
                            />
                            <UtensilsCrossed v-else class="size-7 text-muted-foreground/50" />

                            <!-- Details: the whole card stays scannable; the eye is the door in. -->
                            <Link
                                :href="route('menu.show', { product: item.id })"
                                class="press absolute right-2 top-2 flex size-8 items-center justify-center rounded-full bg-background/85 text-muted-foreground shadow-sm backdrop-blur transition-colors hover:text-foreground"
                                :aria-label="`View ${item.name}`"
                            >
                                <Eye class="size-4" />
                            </Link>

                            <span
                                v-if="item.sold_out"
                                class="absolute inset-x-0 bottom-0 bg-destructive/90 py-1 text-center text-[0.7rem] font-semibold uppercase tracking-wide text-white"
                            >
                                Out of stock
                            </span>
                        </div>

                        <div class="flex flex-1 flex-col gap-0.5 p-3">
                            <h3 class="line-clamp-2 text-sm font-medium leading-snug">{{ item.name }}</h3>

                            <p v-if="item.description" class="line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                                {{ item.description }}
                            </p>

                            <!-- mt-auto pins the prices to the bottom edge so they
                                 line up across cards of unequal title length. -->
                            <div class="mt-auto pt-2">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="tabular font-mono text-base font-semibold text-primary">
                                        {{ money(item.price) }}
                                    </p>
                                    <p class="font-mono text-[0.65rem] uppercase tracking-wider text-muted-foreground/70">
                                        {{ item.unit }}
                                    </p>
                                </div>

                                <!--
                                    Larger sizes, cheapest per unit last. Kept on
                                    the same card because a case and a can are one
                                    item bought two ways, not two things to choose
                                    between.
                                -->
                                <dl v-if="item.packs.length" class="mt-1.5 space-y-0.5 border-t border-border pt-1.5">
                                    <div
                                        v-for="pack in item.packs"
                                        :key="pack.id"
                                        class="flex items-baseline justify-between gap-2"
                                        :class="pack.sold_out && 'opacity-50'"
                                    >
                                        <dt class="truncate text-xs text-muted-foreground">{{ pack.name }}</dt>
                                        <dd class="tabular shrink-0 font-mono text-xs font-medium" :class="pack.sold_out && 'line-through'">
                                            {{ money(pack.price) }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>

            <div v-if="!grouped.length" class="animate-scale py-20 text-center">
                <div class="mx-auto flex size-12 items-center justify-center rounded-full border border-dashed border-border text-muted-foreground">
                    <Search class="size-5" />
                </div>
                <p class="mt-3 font-display text-lg font-semibold">Nothing matches that</p>
                <p class="mt-1 text-sm text-muted-foreground">Try a different word, or browse everything.</p>
                <button
                    type="button"
                    class="press mt-4 rounded-full border border-border px-4 py-2 text-sm transition-colors hover:bg-accent"
                    @click="((search = ''), (activeCategory = null))"
                >
                    Show everything
                </button>
            </div>
        </main>

        <footer class="border-t border-border">
            <div class="mx-auto max-w-5xl px-5 py-8 text-center">
                <p v-if="shop.footer" class="text-sm text-muted-foreground">{{ shop.footer }}</p>
                <p class="mt-1 font-mono text-[0.7rem] uppercase tracking-[0.2em] text-muted-foreground/60">{{ shop.name }} · {{ year }}</p>
                <p class="mt-2 text-[0.7rem] text-muted-foreground/60">© {{ year }} Koeuk Dev. All rights reserved.</p>
            </div>
        </footer>
    </div>
</template>
