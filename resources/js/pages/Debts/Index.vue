<script setup lang="ts">
import StatTile from '@/components/charts/StatTile.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import { currentPerPage } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Eye, HandCoins, Plus, Search, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Debt {
    id: number;
    uuid: string;
    order_no: string;
    total: string;
    paid_amount: string;
    items_count: number;
    created_at: string;
    created_offline_at: string | null;
    customer: { id: number; name: string; phone: string | null } | null;
    cashier: { id: number; name: string } | null;
    items: { id: number; product_name: string; qty: number; unit_price: string; subtotal: string }[];
    payments: { id: number; method: string; amount: string; reference_no: string | null; created_at: string }[];
}

const props = defineProps<{
    debts: Paginated<Debt>;
    filters: { search: string; state: string };
    summary: { open_count: number; owed: string };
    methods: { value: string; label: string }[];
}>();

const { currency, money } = useCurrency();

/* Riel has no fractional unit, so a step or minimum of 0.01 asks for a
   payment that cannot be made. Both follow the currency instead. */
const amountStep = computed(() => (currency.value.decimals > 0 ? '0.01' : '1'));

const search = ref(props.filters.search);
const state = ref(props.filters.state);
let debounce: ReturnType<typeof setTimeout>;

function reload() {
    router.get(
        route('debts.index'),
        { filter: { search: search.value || undefined, state: state.value }, per_page: currentPerPage() },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(reload, 300);
});
watch(state, reload);

const owed = (d: Debt) => Math.max(0, Number(d.total) - Number(d.paid_amount));

const soldAt = (d: Debt) => new Date(d.created_offline_at ?? d.created_at).toLocaleDateString(undefined, { dateStyle: 'medium' });

/* Details sheet: what they took and what they have paid, without leaving
   the list. A debt is a conversation at the counter, and the counter needs
   the whole story on one screen. */
const viewing = ref<Debt | null>(null);

const paidAt = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const methodLabel = (m: string) => (m === 'qr' ? 'QR' : m.charAt(0).toUpperCase() + m.slice(1));

/* Settle dialog. Defaults to the full amount owed — most people pay it off. */
const settling = ref<Debt | null>(null);
const form = useForm({ amount: '', method: 'cash', reference_no: '' });

function openSettle(d: Debt) {
    settling.value = d;
    form.clearErrors();
    form.amount = owed(d).toFixed(2);
    form.method = 'cash';
    form.reference_no = '';
}

const leftAfter = computed(() => (settling.value ? Math.max(0, owed(settling.value) - (Number(form.amount) || 0)) : 0));

function submitSettle() {
    if (!settling.value) return;
    form.post(route('debts.settle', { order: settling.value.uuid }), {
        preserveScroll: true,
        onSuccess: () => (settling.value = null),
    });
}

/* ------------------------------------------------------------------ */
/* Add debt — more on the book without a trip through the till.        */
/* ------------------------------------------------------------------ */

interface PickedCustomer {
    id: number;
    name: string;
    phone: string | null;
}

const adding = ref(false);
const addForm = useForm({
    customer_id: '' as string | number,
    product_id: '' as string | number,
    qty: 1,
    amount: '',
    note: '',
});

/*
 * What they took, when it is a real product. Priced by the catalogue and it
 * moves stock like a till sale — beer on credit is still cans off the shelf.
 * Left unpicked, the dialog falls back to a typed amount, which moves nothing.
 */
interface PickedProduct {
    id: number;
    name: string;
    sell_price: string;
    unit: string;
    units_per_pack: number;
    parent_name: string | null;
}

const pickedProduct = ref<PickedProduct | null>(null);
const productQuery = ref('');
const productResults = ref<PickedProduct[]>([]);
let productDebounce: ReturnType<typeof setTimeout>;
let productSeq = 0;

async function searchProducts() {
    const seq = ++productSeq;

    try {
        const response = await fetch(`${route('debts.products')}?q=${encodeURIComponent(productQuery.value)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(String(response.status));
        const data = await response.json();
        if (seq === productSeq) productResults.value = data.results ?? [];
    } catch {
        if (seq === productSeq) productResults.value = [];
    }
}

watch(productQuery, () => {
    clearTimeout(productDebounce);
    productDebounce = setTimeout(searchProducts, 250);
});

function pickProduct(product: PickedProduct) {
    pickedProduct.value = product;
    addForm.product_id = product.id;
    addForm.qty = 1;
}

function unpickProduct() {
    pickedProduct.value = null;
    addForm.product_id = '';
    void searchProducts();
}

const lineTotal = computed(() => (pickedProduct.value ? Number(pickedProduct.value.sell_price) * Math.max(1, Number(addForm.qty) || 1) : 0));

/** Who the debt lands on. Set by the row's + button, or searched for. */
const picked = ref<PickedCustomer | null>(null);

const customerQuery = ref('');
const customerResults = ref<PickedCustomer[]>([]);
let customerDebounce: ReturnType<typeof setTimeout>;
let customerSeq = 0;

async function searchCustomers() {
    const seq = ++customerSeq;

    try {
        const response = await fetch(`${route('pos.data.customers')}?q=${encodeURIComponent(customerQuery.value)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(String(response.status));
        const data = await response.json();
        if (seq === customerSeq) customerResults.value = data ?? [];
    } catch {
        if (seq === customerSeq) customerResults.value = [];
    }
}

watch(customerQuery, () => {
    clearTimeout(customerDebounce);
    customerDebounce = setTimeout(searchCustomers, 250);
});

function openAdd(customer: PickedCustomer | null = null) {
    adding.value = true;
    addForm.clearErrors();
    addForm.reset();
    picked.value = customer;
    addForm.customer_id = customer?.id ?? '';
    customerQuery.value = '';
    customerResults.value = [];
    pickedProduct.value = null;
    productQuery.value = '';
    productResults.value = [];

    if (!customer) void searchCustomers();
    void searchProducts();
}

function pickCustomer(customer: PickedCustomer) {
    picked.value = customer;
    addForm.customer_id = customer.id;
}

function submitAdd() {
    addForm
        .transform((data) => ({
            ...data,
            // One path or the other, never a blend the server has to untangle.
            product_id: pickedProduct.value ? data.product_id : null,
            qty: pickedProduct.value ? data.qty : null,
            amount: pickedProduct.value ? null : data.amount,
            note: pickedProduct.value ? null : data.note,
        }))
        .post(route('debts.store'), {
            preserveScroll: true,
            onSuccess: () => (adding.value = false),
        });
}
</script>

<template>
    <Head title="In Debt" />

    <AppLayout :breadcrumbs="[{ title: 'In Debt', href: '/debts' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader title="In Debt" description="Sales made on credit. Record money as it comes in; a debt is settled once it is paid in full.">
                <template #actions>
                    <Button class="press" @click="openAdd()">
                        <Plus class="size-4" />
                        Add debt
                    </Button>
                </template>
            </PageHeader>

            <!-- Two facts, one row — even on a phone. -->
            <div class="stagger mb-4 grid grid-cols-2 gap-2 md:gap-4">
                <StatTile
                    label="Still owed"
                    :value="money(summary.owed)"
                    :icon="HandCoins"
                    :tone="Number(summary.owed) > 0 ? 'warning' : 'default'"
                />
                <StatTile label="Open debts" :value="String(summary.open_count)" :icon="HandCoins" hint="Customers who still owe something" />
            </div>

            <div class="animate-rise rounded-xl border border-border bg-card" style="animation-delay: 60ms">
                <!-- Same shape as Order History: full-width search, chips below. -->
                <div class="flex items-center gap-2 border-b border-border p-3">
                    <div class="relative min-w-0 flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Customer, phone or order no…" class="h-10 rounded-full pl-9" autocomplete="off" />
                    </div>
                    <Select v-model="state">
                        <SelectTrigger class="h-10 w-auto min-w-[8rem] shrink-0 rounded-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="open">Still owed</SelectItem>
                            <SelectItem value="settled">Settled</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Phone: one card per debt — who, how much still owed, and the
                     two actions the conversation ends with. -->
                <ul v-if="debts.data.length" class="space-y-2 p-2.5 md:hidden">
                    <li v-for="d in debts.data" :key="d.id" class="overflow-hidden rounded-xl border border-border bg-card">
                        <button type="button" class="row-press block w-full px-3.5 py-3 text-left" @click="viewing = d">
                            <div class="flex items-baseline justify-between gap-3">
                                <p class="truncate font-medium leading-tight">{{ d.customer?.name ?? '—' }}</p>
                                <Badge v-if="owed(d) <= 0" variant="secondary" class="shrink-0">Settled</Badge>
                                <span v-else class="tabular shrink-0 font-mono text-[0.95rem] font-semibold text-destructive">
                                    {{ money(owed(d)) }}
                                </span>
                            </div>
                            <p class="mt-1 truncate text-xs text-muted-foreground">
                                <span v-if="d.customer?.phone" class="tabular font-mono">{{ d.customer.phone }} · </span>
                                <span class="tabular font-mono">{{ d.order_no }}</span> · {{ soldAt(d) }}
                            </p>
                            <p class="tabular mt-1 font-mono text-xs text-muted-foreground">
                                Total {{ money(d.total) }} · Paid {{ money(d.paid_amount) }}
                            </p>
                        </button>

                        <div v-if="owed(d) > 0 || d.customer" class="flex gap-2 border-t border-border px-3.5 py-2">
                            <Button
                                v-if="d.customer"
                                variant="outline"
                                size="sm"
                                class="press flex-1"
                                :aria-label="`Add more debt for ${d.customer.name}`"
                                @click="openAdd(d.customer)"
                            >
                                <Plus class="size-3.5" />
                                Add
                            </Button>
                            <Button v-if="owed(d) > 0" size="sm" class="press flex-1" @click="openSettle(d)">Record payment</Button>
                        </div>
                    </li>
                </ul>

                <div v-if="debts.data.length" class="hidden overflow-x-auto md:block">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Customer</TableHead>
                                <TableHead>Order</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead data-numeric class="text-right">Total</TableHead>
                                <TableHead data-numeric class="text-right">Paid</TableHead>
                                <TableHead data-numeric class="text-right">Owed</TableHead>
                                <TableHead class="w-[1%]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <tbody class="[&_tr:last-child]:border-0">
                            <TableRow v-for="d in debts.data" :key="d.id">
                                <TableCell>
                                    <p class="font-medium leading-tight">{{ d.customer?.name ?? '—' }}</p>
                                    <p v-if="d.customer?.phone" class="tabular font-mono text-xs text-muted-foreground">{{ d.customer.phone }}</p>
                                </TableCell>
                                <TableCell>
                                    <Link :href="route('orders.show', { order: d.uuid })" class="tabular font-mono text-xs hover:underline">{{
                                        d.order_no
                                    }}</Link>
                                </TableCell>
                                <TableCell class="whitespace-nowrap text-sm text-muted-foreground">{{ soldAt(d) }}</TableCell>
                                <TableCell data-numeric class="text-right"><Money :value="d.total" /></TableCell>
                                <TableCell data-numeric class="text-right text-muted-foreground"><Money :value="d.paid_amount" /></TableCell>
                                <TableCell data-numeric class="text-right">
                                    <Badge v-if="owed(d) <= 0" variant="secondary">Settled</Badge>
                                    <span v-else class="tabular font-mono font-semibold text-destructive">{{ money(owed(d)) }}</span>
                                </TableCell>
                                <TableCell>
                                    <div class="flex items-center justify-end gap-1">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="press size-8"
                                            :aria-label="`View ${d.order_no}`"
                                            title="View details"
                                            @click="viewing = d"
                                        >
                                            <Eye class="size-4" />
                                        </Button>
                                        <Button
                                            v-if="d.customer"
                                            variant="outline"
                                            size="sm"
                                            class="press"
                                            :aria-label="`Add more debt for ${d.customer.name}`"
                                            @click="openAdd(d.customer)"
                                        >
                                            <Plus class="size-3.5" />
                                            Add
                                        </Button>
                                        <Button v-if="owed(d) > 0" size="sm" class="press" @click="openSettle(d)">Record payment</Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </tbody>
                    </Table>
                </div>

                <EmptyState
                    v-else
                    :icon="HandCoins"
                    :title="state === 'open' ? 'Nobody owes you anything' : 'No settled debts yet'"
                    description="Choose “In debt” at the till to record a sale on credit."
                />

                <Pagination :links="debts.links" :from="debts.from" :to="debts.to" :total="debts.total" :per-page="debts.per_page" />
            </div>
        </div>

        <!-- Details: the items they took, and every payment so far. -->
        <Sheet :open="!!viewing" @update:open="(v) => !v && (viewing = null)">
            <SheetContent side="right" class="flex w-full flex-col p-0 sm:max-w-lg">
                <SheetHeader class="shrink-0 border-b border-border px-5 pb-4 pt-5 text-left">
                    <SheetTitle>{{ viewing?.customer?.name ?? 'Debt' }}</SheetTitle>
                    <SheetDescription>
                        <span class="tabular font-mono">{{ viewing?.order_no }}</span>
                        <span v-if="viewing"> · {{ soldAt(viewing) }}</span>
                        <span v-if="viewing?.customer?.phone" class="tabular font-mono"> · {{ viewing.customer.phone }}</span>
                    </SheetDescription>
                </SheetHeader>

                <div v-if="viewing" class="min-h-0 flex-1 overflow-y-auto">
                    <!-- Balance, first: it is the number the conversation is about. -->
                    <div class="grid grid-cols-3 divide-x divide-border border-b border-border">
                        <div class="px-4 py-3">
                            <p class="font-mono text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">Total</p>
                            <p class="tabular font-mono text-lg font-semibold">{{ money(viewing.total) }}</p>
                        </div>
                        <div class="px-4 py-3">
                            <p class="font-mono text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">Paid</p>
                            <p class="tabular font-mono text-lg font-semibold">{{ money(viewing.paid_amount) }}</p>
                        </div>
                        <div class="px-4 py-3" :class="owed(viewing) > 0 ? 'bg-destructive/5' : ''">
                            <p class="font-mono text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">Owed</p>
                            <p class="tabular font-mono text-lg font-bold" :class="owed(viewing) > 0 ? 'text-destructive' : 'text-primary'">
                                {{ owed(viewing) > 0 ? money(owed(viewing)) : 'Settled' }}
                            </p>
                        </div>
                    </div>

                    <!-- What they took. -->
                    <section>
                        <h3
                            class="border-b border-border px-5 py-2.5 font-display text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                        >
                            Items ({{ viewing.items.length }})
                        </h3>
                        <ul class="divide-y divide-border">
                            <li v-for="item in viewing.items" :key="item.id" class="flex items-center gap-3 px-5 py-2.5">
                                <span class="tabular w-8 shrink-0 font-mono text-sm text-muted-foreground">{{ item.qty }}×</span>
                                <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ item.product_name }}</span>
                                <span class="tabular shrink-0 font-mono text-xs text-muted-foreground">{{ money(item.unit_price) }}</span>
                                <span class="tabular w-24 shrink-0 text-right font-mono text-sm font-semibold">{{ money(item.subtotal) }}</span>
                            </li>
                        </ul>
                    </section>

                    <!-- What they have paid so far. -->
                    <section>
                        <h3
                            class="border-b border-t border-border px-5 py-2.5 font-display text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                        >
                            Payments ({{ viewing.payments.length }})
                        </h3>
                        <ul v-if="viewing.payments.length" class="divide-y divide-border">
                            <li v-for="pay in viewing.payments" :key="pay.id" class="flex items-center gap-3 px-5 py-2.5">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium">{{ methodLabel(pay.method) }}</span>
                                    <span class="block text-[0.7rem] text-muted-foreground">
                                        {{ paidAt(pay.created_at)
                                        }}<span v-if="pay.reference_no" class="tabular font-mono"> · {{ pay.reference_no }}</span>
                                    </span>
                                </span>
                                <span class="tabular shrink-0 font-mono text-sm font-semibold text-primary">{{ money(pay.amount) }}</span>
                            </li>
                        </ul>
                        <p v-else class="px-5 py-4 text-sm text-muted-foreground">Nothing paid yet.</p>
                    </section>
                </div>

                <div v-if="viewing" class="flex shrink-0 items-center gap-2 border-t border-border p-4">
                    <Button as-child variant="outline" class="press">
                        <Link :href="route('orders.show', { order: viewing.uuid })">Full order</Link>
                    </Button>
                    <Button
                        v-if="owed(viewing) > 0"
                        class="press flex-1"
                        @click="
                            openSettle(viewing);
                            viewing = null;
                        "
                        >Record payment</Button
                    >
                </div>
            </SheetContent>
        </Sheet>

        <!-- Add debt: an amount straight onto the book. -->
        <Dialog v-model:open="adding">
            <DialogContent class="sm:max-w-sm">
                <form @submit.prevent="submitAdd">
                    <DialogHeader>
                        <DialogTitle>Add debt</DialogTitle>
                        <DialogDescription>
                            Goods taken on credit, typed as an amount. It counts as a sale today and is settled with Record payment like any other
                            debt.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-2">
                            <Label>Customer</Label>

                            <!-- Picked: show who, allow changing. -->
                            <div v-if="picked" class="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">{{ picked.name }}</p>
                                    <p v-if="picked.phone" class="tabular truncate font-mono text-xs text-muted-foreground">{{ picked.phone }}</p>
                                </div>
                                <button
                                    type="button"
                                    class="press rounded p-1 text-muted-foreground hover:text-foreground"
                                    aria-label="Choose a different customer"
                                    @click="((picked = null), (addForm.customer_id = ''), searchCustomers())"
                                >
                                    <X class="size-4" />
                                </button>
                            </div>

                            <!-- Not picked: search. -->
                            <template v-else>
                                <div class="relative">
                                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input v-model="customerQuery" placeholder="Name or phone…" class="pl-9" autocomplete="off" autofocus />
                                </div>
                                <ul v-if="customerResults.length" class="max-h-40 overflow-y-auto rounded-lg border border-border">
                                    <li v-for="c in customerResults" :key="c.id" class="border-b border-border last:border-b-0">
                                        <button
                                            type="button"
                                            class="row-press flex w-full items-baseline justify-between gap-2 px-3 py-2 text-left"
                                            @click="pickCustomer(c)"
                                        >
                                            <span class="truncate text-sm">{{ c.name }}</span>
                                            <span v-if="c.phone" class="tabular shrink-0 font-mono text-xs text-muted-foreground">{{ c.phone }}</span>
                                        </button>
                                    </li>
                                </ul>
                                <p v-else class="text-xs text-muted-foreground">
                                    No match. New customers are created from the till or the Customers page.
                                </p>
                            </template>
                            <InputError :message="addForm.errors.customer_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label>What did they take? <span class="text-muted-foreground">(optional)</span></Label>

                            <!-- Picked: the catalogue prices it and the shelf will move. -->
                            <div v-if="pickedProduct" class="rounded-lg border border-border p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">{{ pickedProduct.name }}</p>
                                        <p class="tabular truncate font-mono text-xs text-muted-foreground">
                                            {{ money(pickedProduct.sell_price) }}
                                            <template v-if="pickedProduct.parent_name">
                                                · ×{{ pickedProduct.units_per_pack }} of {{ pickedProduct.parent_name }}
                                            </template>
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="press rounded p-1 text-muted-foreground hover:text-foreground"
                                        aria-label="Type an amount instead"
                                        @click="unpickProduct"
                                    >
                                        <X class="size-4" />
                                    </button>
                                </div>

                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <Label for="debt-qty" class="text-xs text-muted-foreground">Qty</Label>
                                        <Input id="debt-qty" v-model="addForm.qty" type="number" min="1" class="tabular w-20 font-mono" />
                                    </div>
                                    <p class="tabular font-mono text-sm font-semibold text-primary">{{ money(lineTotal) }}</p>
                                </div>
                                <InputError :message="addForm.errors.qty" />
                            </div>

                            <!-- Not picked: search, or just skip to a typed amount below. -->
                            <template v-else>
                                <div class="relative">
                                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input v-model="productQuery" placeholder="Product, SKU or barcode…" class="pl-9" autocomplete="off" />
                                </div>
                                <ul v-if="productResults.length" class="max-h-40 overflow-y-auto rounded-lg border border-border">
                                    <li v-for="pr in productResults" :key="pr.id" class="border-b border-border last:border-b-0">
                                        <button
                                            type="button"
                                            class="row-press flex w-full items-baseline justify-between gap-2 px-3 py-2 text-left"
                                            @click="pickProduct(pr)"
                                        >
                                            <span class="min-w-0 flex-1 truncate text-sm">
                                                {{ pr.name }}
                                                <span v-if="pr.parent_name" class="text-xs text-muted-foreground">
                                                    ×{{ pr.units_per_pack }} {{ pr.parent_name }}
                                                </span>
                                            </span>
                                            <span class="tabular shrink-0 font-mono text-xs font-semibold text-primary">{{
                                                money(pr.sell_price)
                                            }}</span>
                                        </button>
                                    </li>
                                </ul>
                            </template>
                            <InputError :message="addForm.errors.product_id" />
                        </div>

                        <div v-if="!pickedProduct" class="grid gap-2">
                            <Label for="debt-amount">Amount</Label>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">
                                    {{ currency.symbol }}
                                </span>
                                <Input
                                    id="debt-amount"
                                    v-model="addForm.amount"
                                    type="number"
                                    :step="amountStep"
                                    :min="amountStep"
                                    inputmode="decimal"
                                    class="tabular pl-7 font-mono"
                                />
                            </div>
                            <InputError :message="addForm.errors.amount" />
                        </div>

                        <div v-if="!pickedProduct" class="grid gap-2">
                            <Label for="debt-note">What for</Label>
                            <Input id="debt-note" v-model="addForm.note" placeholder="Optional — rice, oil, cigarettes…" />
                            <p class="text-xs text-muted-foreground">Shown on the debt so both sides remember what it was.</p>
                            <InputError :message="addForm.errors.note" />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="adding = false">Cancel</Button>
                        <Button
                            type="submit"
                            class="press"
                            :disabled="addForm.processing || !addForm.customer_id || (pickedProduct ? !addForm.qty : !addForm.amount)"
                        >
                            Add to debt
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="!!settling" @update:open="(v) => !v && (settling = null)">
            <DialogContent class="sm:max-w-sm">
                <form @submit.prevent="submitSettle">
                    <DialogHeader>
                        <DialogTitle>Record a payment</DialogTitle>
                        <DialogDescription>
                            {{ settling?.customer?.name }} owes
                            <strong class="tabular font-mono">{{ money(settling ? owed(settling) : 0) }}</strong> on {{ settling?.order_no }}.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-2">
                            <Label for="amt">Amount received</Label>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">
                                    {{ currency.symbol }}
                                </span>
                                <Input
                                    id="amt"
                                    v-model="form.amount"
                                    type="number"
                                    :step="amountStep"
                                    :min="amountStep"
                                    inputmode="decimal"
                                    class="tabular pl-7 font-mono"
                                />
                            </div>
                            <InputError :message="form.errors.amount" />
                            <p class="text-xs text-muted-foreground">
                                <template v-if="leftAfter > 0"
                                    >Still owed after this: <strong class="tabular font-mono">{{ money(leftAfter) }}</strong></template
                                >
                                <template v-else>This settles the debt in full.</template>
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label>Paid by</Label>
                            <Select v-model="form.method">
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.method" />
                        </div>
                        <div v-if="form.method !== 'cash'" class="grid gap-2">
                            <Label for="ref">Reference</Label>
                            <Input id="ref" v-model="form.reference_no" placeholder="Optional" class="font-mono" />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="settling = null">Cancel</Button>
                        <Button type="submit" class="press" :disabled="form.processing">Record</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
