<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import HistoryButton from '@/components/HistoryButton.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { currentPerPage, imageSrc } from '@/lib/utils';
import type { Category, Paginated, Product } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Boxes, Eye, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{
    products: Paginated<Product>;
    categories: Category[];
    filters: { search?: string; category_id?: string; status?: string };
}>();

/* Controls the signed-in user cannot use are hidden — the policies are the
   actual wall, this just avoids offering a door that opens onto a 403. */
const { may } = usePermissions();

const search = ref(props.filters.search ?? '');
const categoryId = ref(props.filters.category_id ?? 'all');
const status = ref(props.filters.status ?? 'all');

const ALL = 'all';
let debounce: ReturnType<typeof setTimeout>;

function applyFilters() {
    router.get(
        route('products.index'),
        {
            filter: {
                search: search.value || undefined,
                category_id: categoryId.value === ALL ? undefined : categoryId.value,
                status: status.value === ALL ? undefined : status.value,
            },
            per_page: currentPerPage(),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(applyFilters, 300);
});

watch([categoryId, status], applyFilters);

const pendingDelete = ref<Product | null>(null);

function confirmDelete() {
    if (!pendingDelete.value) return;
    router.delete(route('products.destroy', { product: pendingDelete.value.uuid }), {
        preserveScroll: true,
        onFinish: () => (pendingDelete.value = null),
    });
}

/**
 * The dearest way to buy this product, when it is sold in packs as well as
 * singly. Null when there is only one price, so the range collapses to it.
 */
function packMax(product: Product): string | null {
    if (!product.packs_count || !product.pack_max_price) return null;

    return Number(product.pack_max_price) > Number(product.sell_price) ? product.pack_max_price : null;
}

/** Stock is signed — offline sales can legitimately drive it below zero. */
function stockTone(qty: number | null | undefined) {
    const n = qty ?? 0;
    if (n < 0) return 'text-destructive font-semibold';
    if (n === 0) return 'text-muted-foreground';
    if (n <= 10) return 'text-primary';
    return '';
}
</script>

<template>
    <Head title="Products" />

    <AppLayout :breadcrumbs="[{ title: 'Products', href: '/products' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader title="Products" description="Everything you sell, with live stock across all stores.">
                <template #actions>
                    <Button v-if="may('products', 'create')" as-child class="press hidden md:inline-flex">
                        <Link :href="route('products.create')">
                            <Plus class="size-4" />
                            New product
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <div class="list-panel animate-rise" style="animation-delay: 60ms">
                <!-- Filters -->
                <!-- Same shape as Order History: full-width search, chips below. -->
                <!-- One row: search flexes, the filters keep their width and
                     wrap under it on screens too narrow to hold all three. -->
                <div class="flex flex-wrap items-center gap-2 border-b border-border p-3">
                    <div class="relative min-w-[14rem] flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Search name, SKU or barcode…" class="h-10 rounded-full pl-9" autocomplete="off" />
                    </div>

                    <Select v-model="categoryId">
                        <SelectTrigger class="h-10 w-auto min-w-[7.5rem] shrink-0 rounded-full">
                            <SelectValue placeholder="Category" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All categories</SelectItem>
                            <SelectItem v-for="c in categories" :key="c.id" :value="String(c.id)">
                                {{ c.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select v-model="status">
                        <SelectTrigger class="h-10 w-auto min-w-[7rem] shrink-0 rounded-full">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Table -->
                <div v-if="products.data.length" class="hidden overflow-x-auto md:block">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead class="w-[40%]">Product</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead data-numeric class="text-right">Price</TableHead>
                                <TableHead data-numeric class="text-right">Stock</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead class="w-[1%]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TransitionGroup
                            tag="tbody"
                            enter-from-class="opacity-0"
                            enter-active-class="transition-opacity duration-200"
                            leave-to-class="opacity-0"
                            leave-active-class="transition-opacity duration-150"
                            class="[&_tr:last-child]:border-0"
                        >
                            <TableRow v-for="p in products.data" :key="p.id" class="group">
                                <TableCell>
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-muted/50"
                                        >
                                            <img v-if="p.image" :src="imageSrc(p.image)" :alt="p.name" class="size-full object-cover" />
                                            <Boxes v-else class="size-4 text-muted-foreground" />
                                        </div>
                                        <div class="min-w-0">
                                            <Link
                                                :href="route('products.show', { product: p.uuid })"
                                                class="block truncate font-medium leading-tight hover:underline"
                                            >
                                                {{ p.name }}
                                            </Link>
                                            <p class="tabular truncate font-mono text-xs text-muted-foreground">
                                                {{ p.sku }}<span v-if="p.barcode"> · {{ p.barcode }}</span>
                                            </p>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell class="text-muted-foreground">
                                    {{ p.category?.name ?? '—' }}
                                </TableCell>
                                <TableCell data-numeric class="text-right font-medium">
                                    <Money :value="p.sell_price" />
                                    <!-- Packs make one product several prices; the
                                         range says so without listing them all. -->
                                    <span v-if="packMax(p)" class="text-muted-foreground"> – <Money :value="packMax(p)!" /> </span>
                                </TableCell>
                                <TableCell data-numeric class="text-right">
                                    <span class="tabular font-mono text-sm" :class="stockTone(p.stock_qty)">
                                        {{ p.stock_qty ?? 0 }}
                                    </span>
                                    <span class="ml-1 text-xs text-muted-foreground">{{ p.unit }}</span>
                                </TableCell>
                                <TableCell>
                                    <Badge :variant="p.is_active ? 'secondary' : 'outline'">
                                        {{ p.is_active ? 'Active' : 'Inactive' }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <div class="flex items-center gap-1">
                                        <Button as-child variant="ghost" size="icon" class="press size-8">
                                            <Link :href="route('products.show', { product: p.uuid })" aria-label="View">
                                                <Eye class="size-4" />
                                            </Link>
                                        </Button>
                                        <HistoryButton subject-type="Product" :subject-id="p.uuid" :label="p.name" />
                                        <Button v-if="may('products', 'update')" as-child variant="ghost" size="icon" class="press size-8">
                                            <Link :href="route('products.edit', { product: p.uuid })" aria-label="Edit">
                                                <Pencil class="size-4" />
                                            </Link>
                                        </Button>
                                        <Button
                                            v-if="may('products', 'delete')"
                                            variant="ghost"
                                            size="icon"
                                            class="press size-8 text-muted-foreground hover:text-destructive"
                                            aria-label="Delete"
                                            @click="pendingDelete = p"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TransitionGroup>
                    </Table>
                </div>

                <!-- Phone list. Tap the row for details; every action sits labelled in the bar below. -->
                <ul v-if="products.data.length" class="md:hidden">
                    <li v-for="p in products.data" :key="p.id" class="list-row flex-col">
                        <Link :href="route('products.show', { product: p.uuid })" class="list-row-main">
                            <div
                                class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-muted/50"
                            >
                                <img v-if="p.image" :src="imageSrc(p.image)" :alt="p.name" class="size-full object-cover" />
                                <Boxes v-else class="size-5 text-muted-foreground" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium leading-tight">{{ p.name }}</p>
                                <p class="truncate text-xs text-muted-foreground">
                                    <span class="tabular font-mono">{{ p.sku }}</span>
                                    <span v-if="p.category"> · {{ p.category.name }}</span>
                                    <span v-if="!p.is_active" class="text-destructive"> · Inactive</span>
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="font-medium leading-tight">
                                    <Money :value="p.sell_price" />
                                    <span v-if="packMax(p)" class="text-muted-foreground">– <Money :value="packMax(p)!" /></span>
                                </p>
                                <p class="tabular font-mono text-xs" :class="stockTone(p.stock_qty)">{{ p.stock_qty ?? 0 }} {{ p.unit }}</p>
                            </div>
                        </Link>

                        <div class="flex items-stretch gap-2 border-t border-border p-2">
                            <Link
                                :href="route('products.show', { product: p.uuid })"
                                class="press flex h-9 flex-1 items-center justify-center gap-1.5 rounded-lg border border-border text-xs font-medium text-muted-foreground"
                            >
                                <Eye class="size-4" />
                                View
                            </Link>
                            <HistoryButton subject-type="Product" :subject-id="p.uuid" :label="p.name" with-label />
                            <Link
                                v-if="may('products', 'update')"
                                :href="route('products.edit', { product: p.uuid })"
                                class="press flex h-9 flex-1 items-center justify-center gap-1.5 rounded-lg border border-border text-xs font-medium text-muted-foreground"
                            >
                                <Pencil class="size-4" />
                                Edit
                            </Link>
                            <button
                                v-if="may('products', 'delete')"
                                type="button"
                                class="press flex h-9 flex-1 items-center justify-center gap-1.5 rounded-lg border border-border text-xs font-medium text-destructive"
                                @click="pendingDelete = p"
                            >
                                <Trash2 class="size-4" />
                                Delete
                            </button>
                        </div>
                    </li>
                </ul>

                <EmptyState
                    v-else
                    :icon="Boxes"
                    title="No products found"
                    description="Try clearing the filters, or add your first product to the catalogue."
                >
                    <Button as-child variant="outline" class="press">
                        <Link :href="route('products.create')">Add a product</Link>
                    </Button>
                </EmptyState>

                <Pagination :links="products.links" :from="products.from" :to="products.to" :total="products.total" :per-page="products.per_page" />
            </div>
        </div>

        <!-- Phone: create floats bottom-right above the tab bar, in thumb reach. -->
        <Button
            v-if="may('products', 'create')"
            as-child
            class="press fixed right-4 z-40 h-12 rounded-full px-5 shadow-lg md:hidden"
            style="bottom: calc(var(--tabbar-h) + var(--safe-bottom) + 1rem)"
        >
            <Link :href="route('products.create')">
                <Plus class="size-5" />
                New product
            </Link>
        </Button>

        <AlertDialog :open="!!pendingDelete" @update:open="(v) => !v && (pendingDelete = null)">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete “{{ pendingDelete?.name }}”?</AlertDialogTitle>
                    <AlertDialogDescription>
                        If this product has any sales history it will be deactivated instead of deleted, so past receipts keep working.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction class="bg-destructive text-destructive-foreground hover:bg-destructive/90" @click="confirmDelete">
                        Delete
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
