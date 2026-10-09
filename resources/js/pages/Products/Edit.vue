<script setup lang="ts">
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import ProductForm from '@/components/ProductForm.vue';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Category, Product, Stock } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    product: Product;
    categories: Category[];
    vendors: Array<{ id: number; name: string; is_active: boolean }>;
    packs: Array<{ id: number; name: string; units_per_pack: number; sell_price: string; is_active: boolean }>;
    stores: Array<{ id: number; name: string }>;
    stocks: Stock[];
}>();

/** Total on the shelf across stores — what the receive field counts up from. */
const onHand = computed(() => props.stocks.reduce((sum, s) => sum + s.qty, 0));

const tone = (s: Stock) => {
    if (s.qty < 0) return 'text-destructive';
    if (s.low_stock_threshold !== null && s.qty <= s.low_stock_threshold) return 'text-primary';
    return '';
};
</script>

<template>
    <Head :title="product.name" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Products', href: '/products' },
            { title: product.name, href: `/products/${product.id}/edit` },
        ]"
    >
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader :title="product.name">
                <template #actions>
                    <Badge :variant="product.is_active ? 'secondary' : 'outline'">
                        {{ product.is_active ? 'Active' : 'Inactive' }}
                    </Badge>
                    <span class="tabular font-mono text-sm text-muted-foreground">{{ product.sku }}</span>
                </template>
            </PageHeader>

            <!-- Stock is read-only here: it may only change through a sale or an
                 explicit inventory movement, never by editing a product. -->
            <section v-if="stocks.length" class="animate-rise mb-5 rounded-xl border border-border bg-card p-4">
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Stock on hand</h2>
                    <p class="text-xs text-muted-foreground">Adjusted by sales and inventory movements only</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <div v-for="s in stocks" :key="s.id" class="rounded-lg border border-border px-3 py-2">
                        <p class="text-xs text-muted-foreground">{{ s.store?.name }}</p>
                        <p class="tabular font-mono text-lg font-medium" :class="tone(s)">
                            {{ s.qty }}
                            <span class="text-xs text-muted-foreground">{{ product.unit }}</span>
                        </p>
                    </div>
                    <div class="rounded-lg border border-dashed border-border px-3 py-2">
                        <p class="text-xs text-muted-foreground">Sell price</p>
                        <p class="text-lg font-medium">
                            <Money :value="product.sell_price" :muted="false" />
                        </p>
                    </div>
                </div>
            </section>

            <ProductForm :categories="categories" :vendors="vendors" :product="product" :packs="packs" :stores="stores" :on-hand="onHand" />
        </div>
    </AppLayout>
</template>
