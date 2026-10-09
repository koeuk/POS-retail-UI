<script setup lang="ts">
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency, type CurrencyDef } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import { printReceipt } from '@/Pos/composables/usePrint';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CloudOff, HandCoins, Printer, Utensils } from 'lucide-vue-next';
import { computed, onMounted } from 'vue';

interface Item {
    id: number;
    product_name: string;
    qty: number;
    unit_price: string;
    discount: string;
    subtotal: string;
}

const props = defineProps<{
    order: {
        id: number;
        order_no: string;
        status: string;
        sale_type: 'customer' | 'debt' | 'myself';
        subtotal: string;
        discount_amount: string;
        total: string;
        paid_amount: string;
        change_amount: string;
        created_at: string;
        created_offline_at: string | null;
        synced_at: string | null;
        items: Item[];
        payments: { id: number; method: string; amount: string; reference_no: string | null }[];
        cashier: { id: number; name: string } | null;
        store: { id: number; name: string; address: string | null; phone: string | null } | null;
        register: { id: number; name: string } | null;
        customer: { id: number; name: string; phone: string | null; email: string | null } | null;
    };
    settings: { receipt_header: string; receipt_footer: string | null; currency: CurrencyDef };
    /** What is still owed. Non-zero only for an unpaid debt. */
    outstanding: string;
}>();

const { money } = useCurrency(() => props.settings.currency);

const stamp = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');

/* The sale's own moment. For an offline order this is hours or days before
   the row reached the server, and it is the one a customer would recognise. */
const soldAt = computed(() => stamp(props.order.created_offline_at ?? props.order.created_at));

const methodLabel = (m: string) => (m === 'qr' ? 'QR' : m.charAt(0).toUpperCase() + m.slice(1));

const statusTone = (s: string) => (s === 'completed' ? 'secondary' : s === 'refunded' ? 'outline' : 'destructive');

/* "Still in debt" is a live balance, not a flag: it shows while money is
   owed and vanishes the moment the debt is settled. */
const owed = computed(() => Number(props.outstanding));
const stillInDebt = computed(() => props.order.sale_type === 'debt' && owed.value > 0);

/*
 * Arriving with ?print=1 means the printer icon in the order list was clicked,
 * so go straight to the print dialog. The page still renders behind it, so
 * cancelling the dialog leaves the operator on the order rather than nowhere.
 */
onMounted(() => {
    if (new URLSearchParams(window.location.search).get('print')) {
        void printReceipt();
    }
});
</script>

<template>
    <Head :title="order.order_no" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Order History', href: '/orders' },
            { title: order.order_no, href: `/orders/${order.id}` },
        ]"
    >
        <!-- On a phone the app bar already carries the back chevron and the
             order number, and "Sold" sits in the details card — repeating them
             in a header block was pure noise. Print rides in the app bar. -->
        <template #actions>
            <Button variant="ghost" size="icon" class="press size-10 md:hidden" aria-label="Print receipt" @click="printReceipt()">
                <Printer class="size-5" />
            </Button>
        </template>

        <div class="px-2.5 py-6 md:px-8">
            <PageHeader class="hidden md:flex" :title="order.order_no" :description="soldAt">
                <template #actions>
                    <Button as-child variant="ghost" class="press">
                        <Link :href="route('orders.index')">
                            <ArrowLeft class="size-4" />
                            Back
                        </Link>
                    </Button>
                    <Button class="press" @click="printReceipt()">
                        <Printer class="size-4" />
                        Print receipt
                    </Button>
                </template>
            </PageHeader>

            <!-- Loud on purpose: an unpaid debt is the one thing about an order
                 that changes what you should do next. -->
            <div
                v-if="stillInDebt"
                class="animate-rise mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-destructive/40 bg-destructive/10 px-4 py-3"
            >
                <HandCoins class="size-5 shrink-0 text-destructive" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-destructive">Still in debt</p>
                    <p class="text-sm text-muted-foreground">
                        {{ order.customer?.name ?? 'The customer' }} still owes
                        <strong class="tabular font-mono text-foreground">{{ money(owed) }}</strong>
                        of {{ money(order.total) }} on this sale.
                    </p>
                </div>
                <Button as-child size="sm" class="press">
                    <Link :href="route('debts.index', { search: order.order_no })">Record payment</Link>
                </Button>
            </div>
            <div
                v-else-if="order.sale_type === 'myself'"
                class="animate-rise mb-4 flex items-center gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3"
            >
                <Utensils class="size-5 shrink-0 text-muted-foreground" />
                <p class="text-sm text-muted-foreground">Taken for yourself — not counted as a sale.</p>
            </div>

            <div class="grid items-start gap-4 lg:grid-cols-3">
                <!-- Line items + money -->
                <div class="min-w-0 space-y-4 lg:col-span-2">
                    <section class="animate-rise overflow-hidden rounded-xl border border-border bg-card">
                        <h2 class="border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            Items
                        </h2>
                        <div class="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow class="hover:bg-transparent">
                                        <TableHead>Product</TableHead>
                                        <TableHead data-numeric class="text-right">Qty</TableHead>
                                        <TableHead data-numeric class="text-right">Unit</TableHead>
                                        <TableHead data-numeric class="text-right">Line</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <tbody class="[&_tr:last-child]:border-0">
                                    <TableRow v-for="item in order.items" :key="item.id">
                                        <TableCell class="font-medium">
                                            {{ item.product_name }}
                                            <span v-if="Number(item.discount) > 0" class="block text-[0.7rem] text-primary">
                                                less {{ money(item.discount) }} discount
                                            </span>
                                        </TableCell>
                                        <TableCell data-numeric class="tabular text-right font-mono">{{ item.qty }}</TableCell>
                                        <TableCell data-numeric class="text-right text-muted-foreground">
                                            <Money :value="item.unit_price" />
                                        </TableCell>
                                        <TableCell data-numeric class="text-right font-medium">
                                            <Money :value="item.subtotal" :muted="false" />
                                        </TableCell>
                                    </TableRow>
                                </tbody>
                            </Table>
                        </div>

                        <dl class="space-y-1 border-t border-border px-4 py-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Subtotal</dt>
                                <dd class="tabular font-mono">{{ money(order.subtotal) }}</dd>
                            </div>
                            <div v-if="Number(order.discount_amount) > 0" class="flex justify-between text-primary">
                                <dt>Order discount</dt>
                                <dd class="tabular font-mono">− {{ money(order.discount_amount) }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between border-t border-border pt-2">
                                <dt class="font-display text-base font-semibold">Total</dt>
                                <dd class="tabular font-mono text-xl font-bold text-primary">{{ money(order.total) }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="animate-rise rounded-xl border border-border bg-card" style="animation-delay: 60ms">
                        <h2 class="border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            Payment
                        </h2>
                        <ul class="divide-y divide-border">
                            <li v-for="payment in order.payments" :key="payment.id" class="flex items-center gap-3 px-4 py-2.5">
                                <span class="flex-1 text-sm font-medium">{{ methodLabel(payment.method) }}</span>
                                <span v-if="payment.reference_no" class="tabular font-mono text-xs text-muted-foreground">
                                    {{ payment.reference_no }}
                                </span>
                                <Money :value="payment.amount" :muted="false" />
                            </li>
                            <li v-if="Number(order.change_amount) > 0" class="flex items-center gap-3 px-4 py-2.5">
                                <span class="flex-1 text-sm text-muted-foreground">Change given</span>
                                <Money :value="order.change_amount" />
                            </li>
                            <li v-if="stillInDebt" class="flex items-center gap-3 bg-destructive/5 px-4 py-2.5">
                                <span class="flex-1 text-sm font-semibold text-destructive">Still owed</span>
                                <span class="tabular font-mono font-semibold text-destructive">{{ money(owed) }}</span>
                            </li>
                            <li v-if="order.sale_type === 'debt' && !order.payments.length" class="px-4 py-2.5 text-sm text-muted-foreground">
                                Nothing paid yet.
                            </li>
                        </ul>
                    </section>
                </div>

                <!-- Provenance -->
                <section class="animate-rise rounded-xl border border-border bg-card" style="animation-delay: 100ms">
                    <h2 class="border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Details
                    </h2>
                    <dl class="space-y-2.5 px-4 py-3 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Status</dt>
                            <dd>
                                <Badge :variant="statusTone(order.status)" class="capitalize">{{ order.status }}</Badge>
                                <Badge v-if="stillInDebt" variant="destructive" class="ml-1">In debt</Badge>
                                <Badge v-else-if="order.sale_type === 'debt'" variant="outline" class="ml-1">Debt · settled</Badge>
                                <Badge v-else-if="order.sale_type === 'myself'" variant="outline" class="ml-1">Myself</Badge>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Store</dt>
                            <dd class="text-right">{{ order.store?.name ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Register</dt>
                            <dd class="text-right">{{ order.register?.name ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Cashier</dt>
                            <dd class="text-right">{{ order.cashier?.name ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Customer</dt>
                            <dd class="text-right">{{ order.customer?.name ?? 'Walk-in' }}</dd>
                        </div>

                        <div class="border-t border-border pt-2.5">
                            <div class="flex justify-between gap-3">
                                <dt class="text-muted-foreground">Sold</dt>
                                <dd class="text-right">{{ soldAt }}</dd>
                            </div>
                        </div>

                        <!--
                            Only shown for a sale that was rung up offline: the
                            gap between these two timestamps is exactly how long
                            it sat in the queue.
                        -->
                        <div v-if="order.created_offline_at" class="rounded-lg bg-muted/50 p-2.5">
                            <p class="flex items-center gap-1.5 text-xs font-medium text-primary">
                                <CloudOff class="size-3.5" />
                                Rung up offline
                            </p>
                            <p class="mt-1 text-[0.7rem] text-muted-foreground">Reached the server {{ stamp(order.synced_at) }}</p>
                        </div>
                    </dl>
                </section>
            </div>
        </div>

        <!--
            Hidden on screen, printed on demand. Reuses the .receipt-sheet class
            so the print stylesheet that serves the till also serves a reprint
            from the back office, and the paper matches what the customer got.
        -->
        <div class="print-only">
            <article class="receipt-sheet mx-auto w-[302px] bg-white p-4 font-mono text-[11px] leading-snug text-black">
                <header class="text-center">
                    <h1 class="text-sm font-bold uppercase tracking-wide">{{ settings.receipt_header }}</h1>
                    <p class="mt-0.5">{{ order.store?.name }}</p>
                    <p v-if="order.store?.phone">{{ order.store.phone }}</p>
                </header>

                <div class="my-2 border-t border-dashed border-black/40" />

                <dl class="space-y-0.5">
                    <div class="flex justify-between">
                        <dt>Receipt</dt>
                        <dd class="font-bold">{{ order.order_no }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Date</dt>
                        <dd>{{ soldAt }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Cashier</dt>
                        <dd>{{ order.cashier?.name }}</dd>
                    </div>
                </dl>

                <div class="my-2 border-t border-dashed border-black/40" />

                <ul class="space-y-1">
                    <li v-for="item in order.items" :key="item.id">
                        <p>{{ item.product_name }}</p>
                        <div class="flex justify-between">
                            <span>{{ item.qty }} × {{ money(item.unit_price) }}</span>
                            <span>{{ money(item.subtotal) }}</span>
                        </div>
                    </li>
                </ul>

                <div class="my-2 border-t border-dashed border-black/40" />

                <dl class="space-y-0.5">
                    <div class="flex justify-between">
                        <dt>Subtotal</dt>
                        <dd>{{ money(order.subtotal) }}</dd>
                    </div>
                    <div v-if="Number(order.discount_amount) > 0" class="flex justify-between">
                        <dt>Discount</dt>
                        <dd>− {{ money(order.discount_amount) }}</dd>
                    </div>
                    <div class="mt-1 flex justify-between border-t border-black/40 pt-1 text-sm font-bold">
                        <dt>TOTAL</dt>
                        <dd>{{ money(order.total) }}</dd>
                    </div>
                    <div class="flex justify-between pt-1">
                        <dt>Paid ({{ order.payments.map((p) => methodLabel(p.method)).join(' + ') }})</dt>
                        <dd>{{ money(order.paid_amount) }}</dd>
                    </div>
                    <div v-if="Number(order.change_amount) > 0" class="flex justify-between">
                        <dt>Change</dt>
                        <dd>{{ money(order.change_amount) }}</dd>
                    </div>
                    <div v-if="stillInDebt" class="flex justify-between font-bold">
                        <dt>STILL OWED</dt>
                        <dd>{{ money(owed) }}</dd>
                    </div>
                </dl>

                <div class="my-2 border-t border-dashed border-black/40" />

                <footer class="mt-1 text-center">
                    <p v-if="settings.receipt_footer">{{ settings.receipt_footer }}</p>
                    <p class="mt-1">REPRINT</p>
                </footer>
            </article>
        </div>
    </AppLayout>
</template>
