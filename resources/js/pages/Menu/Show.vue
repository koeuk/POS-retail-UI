<script setup lang="ts">
import ThemeToggle from '@/components/ThemeToggle.vue';
import { formatCurrency, type CurrencyDef } from '@/composables/useCurrency';
import { imageSrc } from '@/lib/utils';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, UtensilsCrossed } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface MenuPack {
    id: number;
    name: string;
    units: number;
    price: number;
    sold_out: boolean;
}

const props = defineProps<{
    product: {
        id: number;
        name: string;
        description: string | null;
        image: string | null;
        gallery: string[];
        unit: string;
        category_name: string | null;
        price: number;
        sold_out: boolean;
        packs: MenuPack[];
    };
    shop: { name: string; footer: string | null; currency: CurrencyDef };
}>();

const money = (value: number) => formatCurrency(value, props.shop.currency);

/* All photos in one strip, main shot first; tapping a thumb swaps the stage. */
const photos = computed(() => [props.product.image, ...props.product.gallery].filter((s): s is string => !!s));
const current = ref(0);

const year = new Date().getFullYear();
</script>

<template>
    <Head :title="`${product.name} — ${shop.name}`" />

    <div class="flex min-h-dvh flex-col bg-background text-foreground">
        <!-- Same painted name board as the menu, kept short: this page is about one item. -->
        <header class="surface-brand relative text-brand-foreground">
            <Link
                :href="route('menu')"
                class="press absolute left-4 top-4 flex h-10 items-center gap-2 rounded-full border border-brand-foreground/25 bg-brand-foreground/10 px-3 text-sm font-medium text-brand-foreground/90 transition-colors hover:bg-brand-foreground/20 hover:text-brand-foreground md:left-6 md:top-6"
            >
                <ArrowLeft class="size-4" />
                <span class="hidden sm:inline">Menu</span>
            </Link>

            <ThemeToggle
                class="absolute right-4 top-4 border border-brand-foreground/25 bg-brand-foreground/10 text-brand-foreground/90 hover:bg-brand-foreground/20 hover:text-brand-foreground md:right-6 md:top-6"
            />

            <div class="mx-auto max-w-3xl px-5 py-10 text-center md:py-12">
                <p class="animate-fade font-mono text-[0.7rem] uppercase tracking-[0.28em] text-brand-foreground/85">
                    {{ product.category_name ?? shop.name }}
                </p>
                <h1 class="animate-rise mt-2 font-display text-3xl font-bold tracking-tight md:text-4xl" style="animation-delay: 60ms">
                    {{ product.name }}
                </h1>
            </div>
        </header>

        <main class="mx-auto w-full max-w-3xl flex-1 px-5 py-8">
            <div class="grid gap-6 md:grid-cols-2 md:items-start">
                <!-- Photos -->
                <section class="animate-rise">
                    <div
                        class="relative flex aspect-square w-full items-center justify-center overflow-hidden rounded-xl border border-border bg-muted/40"
                    >
                        <img
                            v-if="photos.length"
                            :src="imageSrc(photos[current])"
                            :alt="product.name"
                            class="size-full object-cover"
                            :class="product.sold_out && 'grayscale'"
                        />
                        <UtensilsCrossed v-else class="size-10 text-muted-foreground/50" />

                        <span
                            v-if="product.sold_out"
                            class="absolute inset-x-0 bottom-0 bg-destructive/90 py-1.5 text-center text-xs font-semibold uppercase tracking-wide text-white"
                        >
                            Out of stock
                        </span>
                    </div>

                    <div v-if="photos.length > 1" class="scrollbar-none mt-2 flex gap-2 overflow-x-auto pb-1">
                        <button
                            v-for="(src, i) in photos"
                            :key="i"
                            type="button"
                            class="size-16 shrink-0 overflow-hidden rounded-lg border-2 transition-opacity"
                            :class="current === i ? 'border-primary' : 'border-transparent opacity-70 hover:opacity-100'"
                            @click="current = i"
                        >
                            <img :src="imageSrc(src)" :alt="`${product.name} photo ${i + 1}`" class="size-full object-cover" />
                        </button>
                    </div>
                </section>

                <!-- Details -->
                <section class="animate-rise space-y-5" style="animation-delay: 60ms">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="tabular font-mono text-3xl font-bold text-primary">{{ money(product.price) }}</p>
                        <p class="font-mono text-xs uppercase tracking-wider text-muted-foreground">per {{ product.unit }}</p>
                    </div>

                    <p v-if="product.description" class="text-sm leading-relaxed text-muted-foreground">
                        {{ product.description }}
                    </p>

                    <!-- Every way to buy it, cheapest per unit last — same one-item logic as the menu card. -->
                    <div v-if="product.packs.length" class="rounded-xl border border-border bg-card">
                        <p class="border-b border-border px-4 py-2.5 font-mono text-[0.65rem] uppercase tracking-[0.18em] text-muted-foreground">
                            Also sold as
                        </p>
                        <dl>
                            <div
                                v-for="pack in product.packs"
                                :key="pack.id"
                                class="flex items-baseline justify-between gap-3 border-b border-border px-4 py-2.5 last:border-b-0"
                                :class="pack.sold_out && 'opacity-50'"
                            >
                                <dt class="text-sm">
                                    {{ pack.name }}
                                    <span class="text-xs text-muted-foreground">· {{ pack.units }} {{ product.unit }}</span>
                                </dt>
                                <dd class="tabular shrink-0 font-mono text-sm font-semibold" :class="pack.sold_out && 'line-through'">
                                    {{ money(pack.price) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </section>
            </div>
        </main>

        <footer class="border-t border-border">
            <div class="mx-auto max-w-3xl px-5 py-6 text-center text-xs text-muted-foreground">
                <p v-if="shop.footer">{{ shop.footer }}</p>
                <p class="mt-1">© {{ year }} {{ shop.name }}</p>
            </div>
        </footer>
    </div>
</template>
