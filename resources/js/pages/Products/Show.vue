<script setup lang="ts">
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import AppLayout from '@/layouts/AppLayout.vue';
import { imageSrc } from '@/lib/utils';
import type { Product, Stock } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Boxes, ChevronLeft, ChevronRight, PackageSearch, Pencil } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Movement {
    id: number;
    type: string;
    qty_change: number;
    note: string | null;
    created_at: string;
    store: { id: number; name: string } | null;
    creator: { id: number; name: string } | null;
}

interface Pack {
    id: number;
    name: string;
    units_per_pack: number;
    sell_price: string;
    is_active: boolean;
}

const props = withDefaults(
    defineProps<{
        product: Product;
        packs?: Pack[];
        stocks: Stock[];
        movements: Movement[];
        sales: { qty: number; revenue: string } | null;
    }>(),
    { packs: () => [] },
);

const onHand = computed(() => props.stocks.reduce((sum, s) => sum + s.qty, 0));

function tone(stock: Stock) {
    if (stock.qty < 0) return 'text-destructive';
    if (stock.low_stock_threshold !== null && stock.qty <= stock.low_stock_threshold) return 'text-primary';
    return '';
}

const when = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });

const typeTone = (type: string) => (type === 'sale' ? 'outline' : type === 'restock' || type === 'return' ? 'secondary' : 'default');

/*
 * Photo viewer: a slide-over from the right. One flat list — the main image
 * first, then the gallery — so next/previous walks everything the product has.
 */
const slides = computed(() => [props.product.image, ...(props.product.gallery ?? [])].filter((s): s is string => !!s));
const viewer = ref<number | null>(null);
const viewerOpen = computed({
    get: () => viewer.value !== null,
    set: (open: boolean) => {
        if (!open) viewer.value = null;
    },
});

/** Where a gallery thumbnail sits in the flat slide list. */
const galleryOffset = computed(() => (props.product.image ? 1 : 0));

function prevSlide() {
    if (viewer.value !== null) viewer.value = (viewer.value + slides.value.length - 1) % slides.value.length;
}

function nextSlide() {
    if (viewer.value !== null) viewer.value = (viewer.value + 1) % slides.value.length;
}
</script>

<template>
    <Head :title="product.name" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Products', href: '/products' },
            { title: product.name, href: `/products/${product.id}` },
        ]"
    >
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader :title="product.name" :description="product.category?.name">
                <template #actions>
                    <Button as-child variant="ghost" class="press">
                        <Link :href="route('products.index')">
                            <ArrowLeft class="size-4" />
                            Back
                        </Link>
                    </Button>
                    <Button as-child class="press">
                        <Link :href="route('products.edit', { product: product.uuid })">
                            <Pencil class="size-4" />
                            Edit
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <div class="grid items-start gap-4 lg:grid-cols-3">
                <!-- Identity -->
                <section class="animate-rise rounded-xl border border-border bg-card p-5">
                    <component
                        :is="product.image ? 'button' : 'div'"
                        :type="product.image ? 'button' : undefined"
                        class="mb-4 flex aspect-square w-full items-center justify-center overflow-hidden rounded-lg border border-border bg-muted/40"
                        :class="product.image && 'lift cursor-zoom-in'"
                        @click="product.image && (viewer = 0)"
                    >
                        <img v-if="product.image" :src="imageSrc(product.image)" :alt="product.name" class="size-full object-cover" />
                        <Boxes v-else class="size-8 text-muted-foreground/50" />
                    </component>

                    <!-- Gallery -->
                    <div v-if="product.gallery?.length" class="mb-4 grid grid-cols-4 gap-2">
                        <button
                            v-for="(src, i) in product.gallery"
                            :key="i"
                            type="button"
                            class="lift aspect-square cursor-zoom-in overflow-hidden rounded-lg border border-border"
                            @click="viewer = i + galleryOffset"
                        >
                            <img :src="imageSrc(src)" :alt="`${product.name} photo ${i + 1}`" class="size-full object-cover" />
                        </button>
                    </div>

                    <div class="flex items-center gap-2">
                        <Badge :variant="product.is_active ? 'secondary' : 'outline'">
                            {{ product.is_active ? 'Active' : 'Inactive' }}
                        </Badge>
                        <Badge v-if="!product.track_stock" variant="outline">Stock not tracked</Badge>
                    </div>

                    <dl class="mt-4 space-y-2.5 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">SKU</dt>
                            <dd class="tabular font-mono">{{ product.sku }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Barcode</dt>
                            <dd class="tabular font-mono">{{ product.barcode ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Unit</dt>
                            <dd>{{ product.unit }}</dd>
                        </div>
                    </dl>

                    <p v-if="product.description" class="mt-4 border-t border-border pt-4 text-sm text-muted-foreground">
                        {{ product.description }}
                    </p>
                </section>

                <!-- min-w-0: a grid item defaults to min-width:auto and will not shrink
                     below its widest row, which pushed this column past the grid. -->
                <div class="min-w-0 space-y-4 lg:col-span-2">
                    <!-- Money -->
                    <section class="animate-rise rounded-xl border border-border bg-card p-5" style="animation-delay: 60ms">
                        <h2 class="mb-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Price</h2>
                        <div class="flex flex-wrap gap-6">
                            <div>
                                <p class="text-xs text-muted-foreground">Price</p>
                                <p class="text-2xl font-bold text-primary">
                                    <Money :value="product.sell_price" :muted="false" />
                                </p>
                                <p class="text-[0.7rem] text-muted-foreground">per {{ product.unit }}</p>

                                <!--
                                    The list page only has room for a range, so
                                    the individual pack prices belong here — with
                                    the per-unit figure, which is the number that
                                    says whether the bulk price is a discount.
                                -->
                                <dl v-if="packs.length" class="mt-3 space-y-1 border-t border-border pt-2">
                                    <div v-for="pack in packs" :key="pack.id" class="flex items-baseline justify-between gap-3">
                                        <dt
                                            class="truncate text-xs"
                                            :class="pack.is_active ? 'text-muted-foreground' : 'text-muted-foreground/50 line-through'"
                                        >
                                            {{ pack.name }}
                                            <span class="tabular font-mono">×{{ pack.units_per_pack }}</span>
                                        </dt>
                                        <dd class="shrink-0 text-right">
                                            <span class="tabular font-mono text-sm font-medium"
                                                ><Money :value="pack.sell_price" :muted="false"
                                            /></span>
                                            <span class="tabular ml-1 font-mono text-[0.65rem] text-muted-foreground">
                                                {{ (Number(pack.sell_price) / pack.units_per_pack).toFixed(3) }} ea
                                            </span>
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                            <div v-if="sales">
                                <p class="text-xs text-muted-foreground">Sold to date</p>
                                <p class="tabular font-mono text-2xl font-semibold">{{ sales.qty }}</p>
                                <p class="text-[0.7rem] text-muted-foreground"><Money :value="sales.revenue" /> revenue</p>
                            </div>
                        </div>
                    </section>

                    <!-- Stock -->
                    <section class="animate-rise rounded-xl border border-border bg-card" style="animation-delay: 100ms">
                        <div class="flex items-baseline justify-between border-b border-border px-4 py-3">
                            <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Stock on hand</h2>
                            <p class="tabular font-mono text-sm font-semibold">{{ onHand }} {{ product.unit }} total</p>
                        </div>
                        <ul v-if="stocks.length" class="divide-y divide-border">
                            <li v-for="stock in stocks" :key="stock.id" class="flex items-center gap-3 px-4 py-2.5">
                                <span class="flex-1 text-sm">{{ stock.store?.name }}</span>
                                <span v-if="stock.low_stock_threshold !== null" class="tabular font-mono text-[0.7rem] text-muted-foreground">
                                    alert at {{ stock.low_stock_threshold }}
                                </span>
                                <span class="tabular font-mono text-base font-semibold" :class="tone(stock)">
                                    {{ stock.qty }}
                                </span>
                            </li>
                        </ul>
                        <p v-else class="px-4 py-6 text-center text-sm text-muted-foreground">No stock rows yet.</p>

                        <div class="border-t border-border px-4 py-3">
                            <Button as-child variant="outline" size="sm" class="press">
                                <Link :href="route('inventory.index', { search: product.sku })">
                                    <PackageSearch class="size-4" />
                                    Adjust in Inventory
                                </Link>
                            </Button>
                        </div>
                    </section>

                    <!-- Ledger -->
                    <section class="animate-rise rounded-xl border border-border bg-card" style="animation-delay: 140ms">
                        <h2 class="border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            Recent movements
                        </h2>
                        <ul v-if="movements.length" class="divide-y divide-border">
                            <li v-for="movement in movements" :key="movement.id" class="flex items-center gap-3 px-4 py-2.5">
                                <Badge :variant="typeTone(movement.type)" class="w-[5.5rem] justify-center capitalize">
                                    {{ movement.type }}
                                </Badge>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[0.75rem] text-muted-foreground">
                                        {{ movement.store?.name }} · {{ movement.creator?.name ?? 'System' }} · {{ when(movement.created_at) }}
                                        <span v-if="movement.note">· {{ movement.note }}</span>
                                    </p>
                                </div>
                                <span
                                    class="tabular shrink-0 font-mono text-sm font-semibold"
                                    :class="movement.qty_change < 0 ? 'text-destructive' : 'text-primary'"
                                >
                                    {{ movement.qty_change > 0 ? '+' : '' }}{{ movement.qty_change }}
                                </span>
                            </li>
                        </ul>
                        <p v-else class="px-4 py-6 text-center text-sm text-muted-foreground">Nothing has moved yet.</p>
                    </section>
                </div>
            </div>
        </div>

        <!-- Photo viewer: slides in from the right; arrows loop through every photo. -->
        <Sheet v-model:open="viewerOpen">
            <SheetContent side="right" class="flex w-full flex-col gap-4 sm:max-w-lg" @keydown.left="prevSlide" @keydown.right="nextSlide">
                <SheetHeader>
                    <SheetTitle class="truncate pr-8">{{ product.name }}</SheetTitle>
                    <SheetDescription v-if="slides.length > 1"> Photo {{ (viewer ?? 0) + 1 }} of {{ slides.length }} </SheetDescription>
                </SheetHeader>

                <div class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-lg border border-border bg-muted/40">
                    <img v-if="viewer !== null" :src="imageSrc(slides[viewer])" :alt="product.name" class="max-h-full max-w-full object-contain" />

                    <template v-if="slides.length > 1">
                        <Button
                            variant="secondary"
                            size="icon"
                            class="press absolute left-2 top-1/2 -translate-y-1/2 rounded-full shadow-md"
                            aria-label="Previous photo"
                            @click="prevSlide"
                        >
                            <ChevronLeft class="size-5" />
                        </Button>
                        <Button
                            variant="secondary"
                            size="icon"
                            class="press absolute right-2 top-1/2 -translate-y-1/2 rounded-full shadow-md"
                            aria-label="Next photo"
                            @click="nextSlide"
                        >
                            <ChevronRight class="size-5" />
                        </Button>
                    </template>
                </div>

                <!-- Filmstrip: jump straight to any photo. -->
                <div v-if="slides.length > 1" class="scrollbar-none flex gap-2 overflow-x-auto pb-1">
                    <button
                        v-for="(src, i) in slides"
                        :key="i"
                        type="button"
                        class="size-14 shrink-0 overflow-hidden rounded-md border-2"
                        :class="viewer === i ? 'border-primary' : 'border-transparent opacity-70'"
                        @click="viewer = i"
                    >
                        <img :src="imageSrc(src)" alt="" class="size-full object-cover" />
                    </button>
                </div>
            </SheetContent>
        </Sheet>
    </AppLayout>
</template>
