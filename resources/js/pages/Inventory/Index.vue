<script setup lang="ts">
import StatTile from '@/components/charts/StatTile.vue';
import EmptyState from '@/components/EmptyState.vue';
import HistoryButton from '@/components/HistoryButton.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { currentPerPage } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Boxes, Layers, LoaderCircle, PackageSearch, Plus, Search, TriangleAlert } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface StockRow {
    id: number;
    uuid: string;
    qty: number;
    low_stock_threshold: number | null;
    product: {
        id: number;
        name: string;
        sku: string;
        barcode: string | null;
        unit: string;
        case_size: number | null;
        packs?: { id: number; name: string; units_per_pack: number }[];
    } | null;
    store: { id: number; name: string } | null;
}

interface Movement {
    id: number;
    type: string;
    qty_change: number;
    note: string | null;
    created_at: string;
    product: { id: number; name: string; unit: string } | null;
    store: { id: number; name: string } | null;
    creator: { id: number; name: string } | null;
}

const props = defineProps<{
    stocks: Paginated<StockRow>;
    filters: { search?: string; store_id?: string; state?: string; sort?: string };
    stores: { id: number; name: string }[];
    movements: Movement[];
    summary: { tracked: number; units: number; low: number; out: number; oversold: number };
}>();

/* Stock writes are gated in InventoryController; this hides the button. */
const { may } = usePermissions();

const ALL = 'all';
const search = ref(props.filters.search ?? '');
const storeId = ref(props.filters.store_id ?? ALL);
const state = ref(props.filters.state ?? ALL);
const sort = ref(props.filters.sort ?? 'qty');
let debounce: ReturnType<typeof setTimeout>;

function reload() {
    router.get(
        route('inventory.index'),
        {
            filter: {
                search: search.value || undefined,
                store_id: storeId.value === ALL ? undefined : storeId.value,
                state: state.value === ALL ? undefined : state.value,
            },
            sort: sort.value === 'qty' ? undefined : sort.value,
            per_page: currentPerPage(),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(reload, 300);
});
watch([storeId, state, sort], reload);

/* ------------------------------------------------------------------ */
/* Movement dialog                                                     */
/* ------------------------------------------------------------------ */

const MODES = [
    { value: 'restock', label: 'Restock', hint: 'New delivery — adds to what is already on hand' },
    { value: 'count', label: 'Count', hint: 'Correct the books to the shelf' },
    { value: 'remove', label: 'Remove', hint: 'Damaged, expired or written off' },
    { value: 'return', label: 'Return', hint: 'A customer brought it back' },
] as const;

/* ------------------------------------------------------------------ */
/* Product picker                                                      */
/* ------------------------------------------------------------------ */

/*
 * Adjusting a product should not require finding its row first. The picker
 * searches the whole catalogue over JSON — the table only ever holds one page,
 * so filtering it would hide most of the products you might want.
 */
const pickerOpen = ref(false);
const pickerQuery = ref('');
const pickerResults = ref<StockRow[]>([]);
const pickerLoading = ref(false);
let pickerDebounce: ReturnType<typeof setTimeout>;
let pickerSeq = 0;

async function runLookup() {
    // Responses can land out of order; only the newest one may paint.
    const seq = ++pickerSeq;
    pickerLoading.value = true;

    try {
        const url = `${route('inventory.lookup')}?q=${encodeURIComponent(pickerQuery.value)}`;
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) throw new Error(String(response.status));

        const data = await response.json();
        if (seq === pickerSeq) pickerResults.value = data.results ?? [];
    } catch {
        if (seq === pickerSeq) pickerResults.value = [];
    } finally {
        if (seq === pickerSeq) pickerLoading.value = false;
    }
}

function openPicker() {
    pickerOpen.value = true;
    pickerQuery.value = '';
    runLookup();
}

watch(pickerQuery, () => {
    clearTimeout(pickerDebounce);
    pickerDebounce = setTimeout(runLookup, 250);
});

const adjusting = ref<StockRow | null>(null);
const form = useForm({
    stock_id: 0,
    mode: 'restock' as string,
    quantity: 0,
    /*
     * How the goods were boxed. Optional and blank by default — a case of
     * water is twelve bottles, a case of something else is not, so nothing is
     * assumed and a bare quantity means single units.
     */
    units_each: '' as string | number,
    unit_label: '',
    loose: '' as string | number,
    note: '',
});

function pick(stock: StockRow) {
    pickerOpen.value = false;
    openAdjust(stock);
}

function openAdjust(stock: StockRow) {
    adjusting.value = stock;
    form.clearErrors();
    form.stock_id = stock.id;
    form.mode = 'restock';
    form.quantity = 0;
    // A cased product's delivery is counted in cases — the dialog already knows the size.
    form.units_each = stock.product?.case_size ?? '';
    form.unit_label = stock.product?.case_size ? 'case' : '';
    form.loose = '';
    form.note = '';
}

/* The number the shelf will read afterwards. Shown live because a count
   correction is absolute while everything else is a delta, and getting those
   two confused is how stock goes wrong. */
/** What was typed, in single units. */
const typedUnits = computed(() => {
    const each = Math.max(1, Number(form.units_each) || 1);

    return (Number(form.quantity) || 0) * each + Math.max(0, Number(form.loose) || 0);
});

/*
 * The first field is "how many cases" the moment a case size is entered —
 * an unlabeled "Quantity" left people guessing whether they were typing
 * cases or units. Falls back to the product's own words when they name
 * the container something else (កេស, pack, box…).
 */
const container = computed(() => form.unit_label.trim() || 'case');
const caseEntry = computed(() => Number(form.units_each) > 1);

/* "cases", "boxes" — but a Khmer word like កេស is left exactly as typed. */
const containerPlural = computed(() => {
    const word = container.value;
    if (!/^[a-z]+$/i.test(word) || word.endsWith('s')) return word;
    return /(x|ch|sh)$/i.test(word) ? `${word}es` : `${word}s`;
});

const resulting = computed(() => {
    if (!adjusting.value) return 0;
    const q = typedUnits.value;

    switch (form.mode) {
        case 'restock':
        case 'return':
            return adjusting.value.qty + q;
        case 'remove':
            return adjusting.value.qty - q;
        default:
            return q;
    }
});

const delta = computed(() => (adjusting.value ? resulting.value - adjusting.value.qty : 0));

function submitAdjust() {
    form.post(route('inventory.store'), {
        preserveScroll: true,
        onSuccess: () => (adjusting.value = null),
    });
}

/* ------------------------------------------------------------------ */
/* Threshold                                                           */
/* ------------------------------------------------------------------ */

const thresholdFor = ref<StockRow | null>(null);
const thresholdForm = useForm({ stock_id: 0, low_stock_threshold: null as number | null });

function openThreshold(stock: StockRow) {
    thresholdFor.value = stock;
    thresholdForm.clearErrors();
    thresholdForm.stock_id = stock.id;
    thresholdForm.low_stock_threshold = stock.low_stock_threshold;
}

/* The Input wants a string; the column wants null for "no alert". Proxy the
   two so an emptied box clears the threshold rather than storing 0, which
   would mean "alert me at zero" — a very different thing. */
const thresholdInput = computed({
    get: () => (thresholdForm.low_stock_threshold === null ? '' : String(thresholdForm.low_stock_threshold)),
    set: (value: string) => {
        thresholdForm.low_stock_threshold = value === '' ? null : Number(value);
    },
});

function submitThreshold() {
    thresholdForm.put(route('inventory.threshold'), {
        preserveScroll: true,
        onSuccess: () => (thresholdFor.value = null),
    });
}

/* ------------------------------------------------------------------ */

const anyFilter = computed(() => !!search.value || storeId.value !== ALL || state.value !== ALL);

/*
 * An empty table after a filter is usually an answer, not a failure. Saying
 * "nothing to show" for a Low stock filter reads as a broken screen when what
 * it actually means is that nothing has fallen to its alert level yet.
 */
const emptyCopy = computed(() => {
    if (search.value) {
        return { title: 'Nothing matches that search', description: 'Check the spelling, or clear the filters below.' };
    }

    switch (state.value) {
        case 'low':
            return { title: 'Nothing is low on stock', description: 'Every tracked product is above its alert level.' };
        case 'out':
            return { title: 'Nothing is out of stock', description: 'No tracked product has hit zero.' };
        case 'oversold':
            return { title: 'Nothing is oversold', description: 'No product has been sold past what the books said was there.' };
        default:
            return { title: 'Nothing to show', description: 'Only active products with a stock row appear here.' };
    }
});

function clearFilters() {
    search.value = '';
    storeId.value = ALL;
    state.value = ALL;
}

/*
 * Stock the way it sits on the shelf: cases and loose units. The product's own
 * case size wins — it exists purely for counting. Failing that, the largest
 * pack the product is sold in stands in. 1,462 reads as "18 cases + 22" when a
 * case holds eighty. A product with neither just shows the number.
 */
function packed(stock: StockRow): { count: number; each: number; label: string; loose: number } | null {
    if (stock.qty <= 0 || !stock.product) return null;

    const pack = (stock.product.packs ?? []).filter((p) => p.units_per_pack > 1).sort((a, b) => b.units_per_pack - a.units_per_pack)[0];
    const each = stock.product.case_size ?? pack?.units_per_pack;
    if (!each) return null;

    const count = Math.floor(stock.qty / each);
    return { count, each, label: stock.product.case_size ? (count === 1 ? 'case' : 'cases') : pack!.name, loose: stock.qty % each };
}

function tone(stock: StockRow) {
    if (stock.qty < 0) return 'text-destructive font-semibold';
    if (stock.qty === 0) return 'text-muted-foreground';
    if (stock.low_stock_threshold !== null && stock.qty <= stock.low_stock_threshold) return 'text-primary';
    return '';
}

const when = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });

const typeTone = (type: string) => (type === 'sale' ? 'outline' : type === 'restock' || type === 'return' ? 'secondary' : 'default');
</script>

<template>
    <Head title="Inventory" />

    <AppLayout :breadcrumbs="[{ title: 'Inventory', href: '/inventory' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader
                title="Inventory"
                description="Stock is never typed in directly — record what happened and the quantity follows, so every change has a reason attached."
            >
                <template #actions>
                    <Button class="press hidden md:inline-flex" @click="openPicker">
                        <Plus class="size-4" />
                        Adjust stock
                    </Button>
                </template>
            </PageHeader>

            <!-- Phone: a swipeable tile rail; a five-tile stack costs a screen
                 and a half of scrolling before the list even starts. -->
            <div
                class="stagger scrollbar-none -mx-2.5 mb-4 flex gap-2 overflow-x-auto px-2.5 sm:mx-0 sm:grid sm:grid-cols-2 sm:gap-4 sm:px-0 lg:grid-cols-3 xl:grid-cols-5 [&>*]:min-w-[11rem] [&>*]:flex-1 sm:[&>*]:min-w-0"
            >
                <StatTile label="Tracked" :value="String(summary.tracked)" :icon="Boxes" :accent="1" hint="Product / store rows" />
                <StatTile label="In stock" :value="summary.units.toLocaleString()" :icon="Layers" :accent="2" hint="Units on hand, all products" />
                <StatTile label="Low stock" :value="String(summary.low)" :icon="TriangleAlert" :accent="4" hint="At or below the alert level" />
                <StatTile label="Out of stock" :value="String(summary.out)" :icon="PackageSearch" :accent="5" hint="Exactly zero on hand" />
                <StatTile
                    label="Oversold"
                    :value="String(summary.oversold)"
                    :icon="TriangleAlert"
                    :tone="summary.oversold > 0 ? 'warning' : 'default'"
                    hint="Below zero — needs a count"
                />
            </div>

            <!--
                70 / 30: the list is the work surface and the ledger is a
                glance. They sit side by side only from xl up — at a laptop
                width the 30% column would be too narrow to read a product
                name, so it stacks underneath instead. min-w-0 on both columns
                keeps a long product name from widening the grid.
            -->
            <div class="grid items-start gap-4 xl:grid-cols-[7fr_3fr]">
                <div class="animate-rise min-w-0 rounded-xl border border-border bg-card" style="animation-delay: 60ms">
                    <!-- Same shape as Order History: full-width search, chips below. -->
                    <!-- One row: search flexes, the filters keep their width and
                         wrap under it on screens too narrow to hold them all. -->
                    <div class="flex flex-wrap items-center gap-2 border-b border-border p-3">
                        <div class="relative min-w-[14rem] flex-1">
                            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input v-model="search" placeholder="Search name, SKU or barcode…" class="h-10 rounded-full pl-9" autocomplete="off" />
                        </div>

                        <Select v-if="stores.length > 1" v-model="storeId">
                            <SelectTrigger class="h-10 w-auto min-w-[7rem] shrink-0 rounded-full"><SelectValue placeholder="Store" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL">All stores</SelectItem>
                                <SelectItem v-for="s in stores" :key="s.id" :value="String(s.id)">{{ s.name }}</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select v-model="state">
                            <SelectTrigger class="h-10 w-auto min-w-[7rem] shrink-0 rounded-full"
                                ><SelectValue placeholder="Anything"
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL">Anything</SelectItem>
                                <SelectItem value="low">Low stock</SelectItem>
                                <SelectItem value="out">Out of stock</SelectItem>
                                <SelectItem value="oversold">Oversold</SelectItem>
                            </SelectContent>
                        </Select>

                        <!-- Order, separate from the state filter: "what is oversold"
                         and "show me the emptiest first" are different questions. -->
                        <Select v-model="sort">
                            <SelectTrigger class="h-10 w-auto min-w-[10rem] shrink-0 rounded-full" aria-label="Sort by"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="qty">Stock: low to high</SelectItem>
                                <SelectItem value="-qty">Stock: high to low</SelectItem>
                                <SelectItem value="name">Name A–Z</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <!-- Phone: one card per shelf row. -->
                    <ul v-if="stocks.data.length" class="space-y-2 p-2.5 md:hidden">
                        <li v-for="stock in stocks.data" :key="stock.id" class="rounded-xl border border-border bg-card px-3.5 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-medium leading-tight">{{ stock.product?.name }}</p>
                                    <p class="tabular truncate font-mono text-xs text-muted-foreground">
                                        {{ stock.product?.sku }}<template v-if="stores.length > 1"> · {{ stock.store?.name }}</template>
                                    </p>
                                </div>
                                <p class="shrink-0 text-right">
                                    <span class="tabular font-mono text-lg font-semibold" :class="tone(stock)">{{ stock.qty.toLocaleString() }}</span>
                                    <span class="ml-1 text-xs text-muted-foreground">{{ stock.product?.unit }}</span>
                                </p>
                            </div>

                            <p v-if="packed(stock)" class="tabular mt-1 font-mono text-xs text-muted-foreground">
                                {{ packed(stock)!.count }} {{ packed(stock)!.label }} · {{ packed(stock)!.each }} each<template
                                    v-if="packed(stock)!.loose"
                                >
                                    + {{ packed(stock)!.loose }} loose</template
                                >
                            </p>

                            <div class="mt-2 flex items-center justify-between gap-2 border-t border-border pt-2">
                                <button
                                    type="button"
                                    class="press tabular rounded-md px-2 py-1 font-mono text-xs text-muted-foreground"
                                    :disabled="!may('inventory', 'update')"
                                    @click="openThreshold(stock)"
                                >
                                    Alert at {{ stock.low_stock_threshold ?? '—' }}
                                </button>
                                <Button v-if="may('inventory', 'update')" size="sm" variant="outline" class="press" @click="openAdjust(stock)">
                                    Adjust
                                </Button>
                            </div>
                        </li>
                    </ul>

                    <div v-if="stocks.data.length" class="hidden overflow-x-auto md:block">
                        <Table>
                            <TableHeader>
                                <TableRow class="hover:bg-transparent">
                                    <TableHead>Product</TableHead>
                                    <!-- Only worth a column when there is more than one. -->
                                    <TableHead v-if="stores.length > 1">Store</TableHead>
                                    <TableHead data-numeric class="text-right">Quantity</TableHead>
                                    <TableHead data-numeric class="text-right">On hand</TableHead>
                                    <TableHead data-numeric class="text-right">Alert at</TableHead>
                                    <TableHead class="w-[1%]"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <tbody class="[&_tr:last-child]:border-0">
                                <TableRow v-for="stock in stocks.data" :key="stock.id" class="group">
                                    <TableCell>
                                        <p class="font-medium leading-tight">{{ stock.product?.name }}</p>
                                        <p class="tabular font-mono text-xs text-muted-foreground">{{ stock.product?.sku }}</p>
                                    </TableCell>
                                    <TableCell v-if="stores.length > 1" class="text-sm text-muted-foreground">{{ stock.store?.name }}</TableCell>
                                    <TableCell data-numeric class="text-right">
                                        <template v-if="packed(stock)">
                                            <span class="tabular font-mono text-base">{{ packed(stock)!.count }}</span>
                                            <span class="ml-1 text-xs text-muted-foreground"
                                                >{{ packed(stock)!.label }} · {{ packed(stock)!.each }} each</span
                                            >
                                            <p v-if="packed(stock)!.loose" class="tabular font-mono text-xs text-muted-foreground">
                                                + {{ packed(stock)!.loose }} loose
                                            </p>
                                        </template>
                                        <span v-else class="tabular font-mono text-base" :class="tone(stock)">{{ stock.qty.toLocaleString() }}</span>
                                    </TableCell>
                                    <TableCell data-numeric class="text-right">
                                        <span class="tabular font-mono text-base" :class="tone(stock)">{{ stock.qty }}</span>
                                        <span class="ml-1 text-xs text-muted-foreground">{{ stock.product?.unit }}</span>
                                    </TableCell>
                                    <TableCell data-numeric class="text-right">
                                        <button
                                            type="button"
                                            class="press tabular rounded-md px-2 py-1 font-mono text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                            :disabled="!may('inventory', 'update')"
                                            @click="openThreshold(stock)"
                                        >
                                            {{ stock.low_stock_threshold ?? '—' }}
                                        </button>
                                    </TableCell>
                                    <TableCell>
                                        <div class="flex items-center justify-end gap-1">
                                            <!-- Stock-row audit: threshold edits and the like.
                                                 Movements themselves live in the ledger below. -->
                                            <HistoryButton subject-type="Stock" :subject-id="stock.uuid" :label="stock.product?.name ?? 'stock'" />
                                            <Button
                                                v-if="may('inventory', 'update')"
                                                size="sm"
                                                variant="outline"
                                                class="press"
                                                @click="openAdjust(stock)"
                                            >
                                                Adjust
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </tbody>
                        </Table>
                    </div>

                    <EmptyState v-else :icon="PackageSearch" :title="emptyCopy.title" :description="emptyCopy.description">
                        <Button v-if="anyFilter" variant="outline" class="press" @click="clearFilters">Show everything</Button>
                    </EmptyState>

                    <Pagination :links="stocks.links" :from="stocks.from" :to="stocks.to" :total="stocks.total" :per-page="stocks.per_page" />
                </div>

                <!-- The ledger. Every row on the left got here through one of these. -->
                <section class="animate-rise min-w-0 rounded-xl border border-border bg-card" style="animation-delay: 120ms">
                    <h2 class="border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Recent movements
                    </h2>
                    <ul v-if="movements.length" class="divide-y divide-border">
                        <!--
                        Two lines per row rather than one: in a 30% column the
                        badge, name and delta cannot share a line without the
                        name being cut to a few letters. Type and change sit
                        together on top; who, when and why sit underneath.
                    -->
                        <li v-for="movement in movements" :key="movement.id" class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <Badge :variant="typeTone(movement.type)" class="shrink-0 capitalize">{{ movement.type }}</Badge>
                                <p class="min-w-0 flex-1 truncate text-sm font-medium">{{ movement.product?.name }}</p>
                                <span
                                    class="tabular shrink-0 font-mono text-sm font-semibold"
                                    :class="movement.qty_change < 0 ? 'text-destructive' : 'text-primary'"
                                >
                                    {{ movement.qty_change > 0 ? '+' : '' }}{{ movement.qty_change }}
                                </span>
                            </div>
                            <p class="mt-0.5 truncate text-[0.7rem] text-muted-foreground">
                                <template v-if="stores.length > 1">{{ movement.store?.name }} · </template>{{ movement.creator?.name ?? 'System' }} ·
                                {{ when(movement.created_at) }}
                                <span v-if="movement.note">· {{ movement.note }}</span>
                            </p>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">No movements recorded yet.</p>
                </section>
            </div>
        </div>

        <!-- Record a movement -->
        <!-- Product picker -->
        <Dialog v-model:open="pickerOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Adjust stock</DialogTitle>
                    <DialogDescription>Search the catalogue by name, SKU or barcode.</DialogDescription>
                </DialogHeader>

                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <LoaderCircle v-if="pickerLoading" class="absolute right-3 top-1/2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />
                    <Input v-model="pickerQuery" placeholder="Search name, SKU or barcode…" class="pl-9" autocomplete="off" autofocus />
                </div>

                <ul v-if="pickerResults.length" class="-mx-1 max-h-[45vh] overflow-y-auto">
                    <li v-for="row in pickerResults" :key="row.id">
                        <button type="button" class="row-press flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left" @click="pick(row)">
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium leading-tight">{{ row.product?.name }}</p>
                                <p class="tabular truncate font-mono text-xs text-muted-foreground">
                                    {{ row.product?.sku }}<span v-if="stores.length > 1"> · {{ row.store?.name }}</span>
                                </p>
                            </div>

                            <!-- The figure is the point of the picker: you choose
                                 what to adjust by seeing where the stock is. -->
                            <p class="shrink-0 text-right">
                                <span class="tabular font-mono text-base" :class="tone(row)">{{ row.qty }}</span>
                                <span class="ml-1 text-xs text-muted-foreground">{{ row.product?.unit }}</span>
                            </p>
                        </button>
                    </li>
                </ul>

                <p v-else-if="!pickerLoading" class="px-1 py-6 text-center text-sm text-muted-foreground">
                    {{ pickerQuery ? 'Nothing matches that.' : 'No tracked products yet.' }}
                </p>
            </DialogContent>
        </Dialog>

        <Dialog :open="!!adjusting" @update:open="(v) => !v && (adjusting = null)">
            <DialogContent class="sm:max-w-md">
                <form @submit.prevent="submitAdjust">
                    <DialogHeader>
                        <DialogTitle>{{ adjusting?.product?.name }}</DialogTitle>
                        <DialogDescription>
                            {{ adjusting?.store?.name }} · currently <strong class="tabular font-mono">{{ adjusting?.qty }}</strong>
                            {{ adjusting?.product?.unit }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid grid-cols-2 gap-1.5">
                            <button
                                v-for="m in MODES"
                                :key="m.value"
                                type="button"
                                class="press rounded-lg border px-3 py-2 text-left"
                                :class="form.mode === m.value ? 'border-primary bg-primary/10' : 'border-border'"
                                @click="form.mode = m.value"
                            >
                                <span class="block text-sm font-medium">{{ m.label }}</span>
                                <span class="block text-[0.7rem] leading-tight text-muted-foreground">{{ m.hint }}</span>
                            </button>
                        </div>
                        <InputError :message="form.errors.mode" />

                        <div class="grid gap-2">
                            <Label for="qty">
                                {{ form.mode === 'count' ? 'Counted on the shelf' : caseEntry ? `Number of ${containerPlural}` : 'Quantity' }}
                            </Label>
                            <Input id="qty" v-model="form.quantity" type="number" min="0" inputmode="numeric" class="tabular font-mono" />
                            <InputError :message="form.errors.quantity" />
                        </div>

                        <!--
                            Goods arrive, and are counted, the way they are
                            boxed: five cases of twelve and three loose, not
                            sixty-three. Both blank by default — case sizes vary
                            by product, so nothing is assumed.
                        -->
                        <div class="grid gap-2">
                            <Label for="units-each">
                                {{ adjusting?.product?.unit ?? 'Units' }} in each {{ container }}
                                <span class="text-muted-foreground">(optional)</span>
                            </Label>
                            <div class="flex gap-2">
                                <Input
                                    id="units-each"
                                    v-model="form.units_each"
                                    type="number"
                                    min="1"
                                    inputmode="numeric"
                                    placeholder="12"
                                    class="tabular w-24 font-mono"
                                    aria-label="Units in each container"
                                />
                                <Input
                                    v-model="form.unit_label"
                                    :placeholder="adjusting?.product?.unit ?? 'case'"
                                    class="flex-1"
                                    aria-label="What the container is called"
                                />
                            </div>
                            <InputError :message="form.errors.units_each" />
                        </div>

                        <div v-if="Number(form.units_each) > 1" class="grid gap-2">
                            <Label for="loose">Plus loose {{ adjusting?.product?.unit }}</Label>
                            <Input
                                id="loose"
                                v-model="form.loose"
                                type="number"
                                min="0"
                                inputmode="numeric"
                                placeholder="0"
                                class="tabular font-mono"
                            />
                            <InputError :message="form.errors.loose" />
                        </div>

                        <div class="flex items-baseline justify-between rounded-lg border border-border px-3 py-2 text-sm">
                            <span class="text-muted-foreground">Will be</span>
                            <span>
                                <span class="tabular font-mono text-lg font-semibold" :class="resulting < 0 ? 'text-destructive' : ''">
                                    {{ resulting }}
                                </span>
                                <span
                                    class="tabular ml-2 font-mono text-xs"
                                    :class="delta < 0 ? 'text-destructive' : delta > 0 ? 'text-primary' : 'text-muted-foreground'"
                                >
                                    ({{ delta > 0 ? '+' : '' }}{{ delta }})
                                </span>
                            </span>
                        </div>

                        <div class="grid gap-2">
                            <Label for="note">Note</Label>
                            <Input id="note" v-model="form.note" placeholder="Optional — why this changed" />
                            <InputError :message="form.errors.note" />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="adjusting = null">Cancel</Button>
                        <Button type="submit" class="press" :disabled="form.processing">Record movement</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Threshold is a setting, not a movement, so it gets its own small form. -->
        <Dialog :open="!!thresholdFor" @update:open="(v) => !v && (thresholdFor = null)">
            <DialogContent class="sm:max-w-sm">
                <form @submit.prevent="submitThreshold">
                    <DialogHeader>
                        <DialogTitle>Low-stock alert</DialogTitle>
                        <DialogDescription>
                            Flag {{ thresholdFor?.product?.name }} at {{ thresholdFor?.store?.name }} once it drops to this level. Leave blank for no
                            alert.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2 py-5">
                        <Label for="threshold">Alert at</Label>
                        <Input id="threshold" v-model="thresholdInput" type="number" min="0" placeholder="No alert" class="tabular font-mono" />
                        <InputError :message="thresholdForm.errors.low_stock_threshold" />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="thresholdFor = null">Cancel</Button>
                        <Button type="submit" class="press" :disabled="thresholdForm.processing">Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Phone: adjust floats bottom-right above the tab bar, in thumb reach. -->
        <Button
            class="press fixed right-4 z-40 h-12 rounded-full px-5 shadow-lg md:hidden"
            style="bottom: calc(var(--tabbar-h) + var(--safe-bottom) + 1rem)"
            @click="openPicker"
        >
            <Plus class="size-5" />
            Adjust stock
        </Button>
    </AppLayout>
</template>
