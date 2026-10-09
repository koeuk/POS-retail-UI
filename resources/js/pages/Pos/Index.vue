<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { USD } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import Cart from '@/Pos/components/Cart.vue';
import Checkout from '@/Pos/components/Checkout.vue';
import CustomerPicker from '@/Pos/components/CustomerPicker.vue';
import PaymentModal from '@/Pos/components/PaymentModal.vue';
import ProductGrid from '@/Pos/components/ProductGrid.vue';
import SyncStatusBadge from '@/Pos/components/SyncStatusBadge.vue';
import { useBarcode } from '@/Pos/composables/useBarcode';
import { useCart } from '@/Pos/composables/useCart';
import { useNavLock } from '@/Pos/composables/useNavLock';
import { useOfflineSync } from '@/Pos/composables/useOfflineSync';
import { cacheFeed, queueOrder, readCachedFeed } from '@/Pos/db/dexie';
import { http } from '@/Pos/lib/http';
import { formatMoney, toDecimalString } from '@/Pos/lib/money';
import type { PaymentMethod, PosFeed, StoredOrder } from '@/Pos/types';
import { Head } from '@inertiajs/vue3';
import { CircleAlert, LoaderCircle, ShoppingCart } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue';

const props = defineProps<{
    boot: {
        store_id: number;
        store_name: string;
        cashier_id: number;
        cashier_name: string;
    };
}>();

const cart = useCart();
const sync = useOfflineSync();
const navLock = useNavLock();

const feed = ref<PosFeed | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const registerId = ref<number | null>(null);
const paying = ref(false);
const paymentOpen = ref(false);
const pickerOpen = ref(false);
// True when the picker was opened by "Record debt": same form, plus the money step.
const pickerAsksDeposit = ref(false);

/*
 * Only a customer sale takes money at the till, so only it opens the
 * payment modal. A debt and an owner take-out complete straight away with
 * nothing paid — asking a cashier to key "0.00 cash" for those would be
 * theatre, and the wrong number to record.
 */
function startCheckout() {
    if (cart.saleType === 'customer') {
        paymentOpen.value = true;
        return;
    }

    // A debt often starts with some money down. The picker handles both
    // halves in one form: who owes, and how much of it is paid right now.
    // Owner take-outs stay silent: no money moves, nothing to key in.
    if (cart.saleType === 'debt') {
        pickerAsksDeposit.value = true;
        pickerOpen.value = true;
        return;
    }

    void completeSale({ method: 'cash', amount: 0, reference: null });
}

/** The deposit is ordinary cash — it flows through the same payment path. */
function recordDebt(c: { id: number; name: string }, deposit: number) {
    cart.customerId = c.id;
    cart.customerName = c.name;
    pickerOpen.value = false;
    void completeSale({ method: 'cash', amount: deposit, reference: null });
}

function attachCustomer(c: { id: number; name: string }) {
    cart.customerId = c.id;
    cart.customerName = c.name;
    pickerOpen.value = false;
}

/*
 * On a phone the cart lives in a sheet rather than stacked under the grid.
 * Stacking gave the grid a few squeezed rows and pushed the total off-screen;
 * a summary bar plus a sheet is what every till app converges on because the
 * grid is where the work happens and the cart is only consulted.
 */
const cartOpen = ref(false);
const toast = ref<{ kind: 'ok' | 'warn'; text: string } | null>(null);

const currency = computed(() => feed.value?.settings.currency ?? USD);
const products = computed(() => feed.value?.products ?? []);

/*
 * Close the door whenever there is unsynced work. Navigating away would
 * unload the page that owns the flush loop, leaving real sales sitting in
 * IndexedDB with nothing driving them to the server.
 */
watchEffect(() => {
    if (!sync.online.value) {
        navLock.lock('Offline — stay here until sales have synced');
    } else if (sync.pending.value > 0) {
        navLock.lock(`${sync.pending.value} sale(s) still syncing`);
    } else {
        navLock.unlock();
    }
});

onBeforeUnmount(() => navLock.unlock());

/**
 * Cache first, network second: the catalogue paints instantly from Dexie
 * even with no signal, and refreshes behind the cashier only if the server
 * actually answers.
 */
async function loadFeed() {
    loading.value = true;
    loadError.value = null;

    const cached = await readCachedFeed();

    if (cached) {
        feed.value = cached as PosFeed;
        loading.value = false;
    }

    try {
        const { data } = await http.get<PosFeed>('/products');
        feed.value = data;
        await cacheFeed(data);
    } catch {
        if (!cached) {
            loadError.value = 'No catalogue yet, and the server is unreachable. Connect once to set this device up.';
        }
    } finally {
        loading.value = false;
        pickRegister();
    }
}

function pickRegister() {
    const registers = feed.value?.registers ?? [];
    const remembered = Number(localStorage.getItem('pos.register_id'));

    registerId.value = registers.find((r) => r.id === remembered)?.id ?? registers[0]?.id ?? null;
}

function chooseRegister(id: number) {
    registerId.value = id;
    localStorage.setItem('pos.register_id', String(id));
}

function flash(kind: 'ok' | 'warn', text: string) {
    toast.value = { kind, text };
    setTimeout(() => (toast.value = null), 3200);
}

useBarcode({
    onScan: (code) => {
        const match = products.value.find((p) => p.barcode === code || p.sku === code);

        if (match) {
            cart.add(match);
            return;
        }

        flash('warn', `No product with code ${code}`);
    },
});

/**
 * Completing a sale is a **local** operation. The order goes into Dexie and
 * the cart clears straight away; reaching the server is a separate, retryable
 * concern. A cashier must never wait on the network to serve the next person.
 */
async function completeSale(payment: { method: PaymentMethod; amount: number; reference: string | null }) {
    paying.value = true;

    const totals = cart.totals;
    const change = payment.method === 'cash' ? Math.max(0, payment.amount - totals.total) : 0;

    const order: StoredOrder = {
        // Generated before the network is involved — this is what makes a
        // retry safe, because the server collapses duplicates on it.
        client_uuid: crypto.randomUUID(),
        store_id: props.boot.store_id,
        register_id: registerId.value,
        customer_id: cart.customerId,
        sale_type: cart.saleType,
        created_offline_at: new Date().toISOString(),
        discount_amount: toDecimalString(totals.discount, currency.value.decimals),
        items: cart.lines.map((line) => ({
            product_id: line.productId,
            product_name: line.name,
            qty: line.qty,
            unit_price: toDecimalString(line.unitPrice, currency.value.decimals),
            discount: toDecimalString(line.discount, currency.value.decimals),
        })),
        payments: [
            {
                method: payment.method,
                amount: toDecimalString(payment.amount, currency.value.decimals),
                reference_no: payment.reference,
            },
        ],
        state: 'pending_sync',
        order_no: null,
        total: toDecimalString(totals.total, currency.value.decimals),
        attempts: 0,
        last_error: null,
        receipt: {
            subtotal: toDecimalString(totals.subtotal, currency.value.decimals),
            total: toDecimalString(totals.total, currency.value.decimals),
            paid: toDecimalString(payment.amount, currency.value.decimals),
            change: toDecimalString(change),
            cashier: props.boot.cashier_name,
            store: props.boot.store_name,
        },
    };

    try {
        await queueOrder(order);
    } catch {
        // IndexedDB itself failed. This is the only case where a sale cannot
        // be recorded, and the cashier must know before the customer leaves.
        flash('warn', 'Could not save the sale locally. Do not let the customer leave.');
        paying.value = false;
        return;
    }

    cart.clear();
    paymentOpen.value = false;
    cartOpen.value = false;
    paying.value = false;
    await sync.refreshCount();

    flash('ok', `Sale saved · ${formatMoney(Number(order.total), currency.value)}`);

    void sync.flush().then(() => {
        if (sync.online.value) void refreshStockHints();
    });
}

/** Stock hints drift as sales land; refresh quietly, never blocking. */
async function refreshStockHints() {
    try {
        const { data } = await http.get<PosFeed>('/products');
        feed.value = data;
        await cacheFeed(data);
    } catch {
        /* a stale hint is harmless — the server is the ledger */
    }
}

onMounted(loadFeed);
</script>

<template>

    <Head title="Point of Sale" />

    <AppLayout :breadcrumbs="[{ title: 'Point of Sale', href: '/pos' }]">
        <!--
            Connection state belongs in the app header, not buried beside the
            cart: it is the one thing a cashier must be able to glance at
            without looking away from what they are ringing up.
        -->
        <template #actions>
            <div v-if="feed && feed.registers.length > 1" class="flex gap-1">
                <button v-for="r in feed.registers" :key="r.id" type="button"
                    class="press h-8 rounded-md border px-2.5 text-xs font-medium"
                    :class="registerId === r.id ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground'"
                    @click="chooseRegister(r.id)">
                    {{ r.name }}
                </button>
            </div>

            <SyncStatusBadge :online="sync.online.value" :syncing="sync.syncing.value" :pending="sync.pending.value"
                :rejected="sync.rejected.value" :auth-expired="sync.authExpired.value"
                @retry="sync.rejected.value > 0 ? sync.retryRejected() : sync.flush()" />
        </template>

        <!-- Fills the viewport under the layout chrome. The grid and cart each
             scroll internally so the page itself never does — a till that
             scrolls as a whole is miserable on a tablet. -->
        <!--
            Fills the viewport exactly. The layout wrapper already reserves the
            tab bar via pb-tabbar, so this height must subtract it too or the
            page gains a scrollbar it should never have. The grid and cart each
            scroll internally instead.
        -->
        <div
            class="flex h-[calc(100dvh-var(--safe-top)-var(--appbar-h)-var(--tabbar-h)-var(--safe-bottom))] min-h-[24rem] flex-col md:h-[calc(100dvh-4rem)]">
            <div v-if="sync.authExpired.value"
                class="shrink-0 border-b border-destructive/30 bg-destructive/10 px-4 py-2 text-center text-xs text-destructive">
                Your session expired. Sales are still saved on this device —
                <a href="/login" class="underline">sign in again</a> to sync them.
            </div>

            <div v-if="loading" class="flex flex-1 items-center justify-center">
                <LoaderCircle class="size-6 animate-spin text-muted-foreground" />
            </div>

            <div v-else-if="loadError" class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
                <CircleAlert class="size-8 text-destructive" />
                <p class="max-w-sm text-sm text-muted-foreground">{{ loadError }}</p>
                <button type="button"
                    class="press h-10 rounded-lg bg-primary px-5 text-sm font-medium text-primary-foreground"
                    @click="loadFeed">
                    Try again
                </button>
            </div>

            <main v-else class="flex min-h-0 flex-1 flex-col lg:flex-row">
                <section class="min-h-0 flex-1 lg:border-r lg:border-border">
                    <ProductGrid :products="products" :categories="feed!.categories" :currency="currency"
                        @add="cart.add($event)" />
                </section>

                <!-- Desktop keeps the cart permanently alongside the grid. -->
                <aside class="hidden min-h-0 w-[24rem] shrink-0 flex-col lg:flex">
                    <Cart :currency="currency" />
                    <Checkout :currency="currency" @pay="startCheckout"
                        @pick-customer="((pickerAsksDeposit = false), (pickerOpen = true))" />
                </aside>
            </main>

            <!--
                Phone summary bar. Sits inside the fixed-height column rather
                than floating, so it can never cover a product tile, and it
                carries the running total — the number a customer asks for
                before the cart is ever opened.
            -->
            <button v-if="!loading && !loadError" type="button"
                class="press flex shrink-0 items-center gap-3 border-t border-border bg-card px-4 py-3 text-left lg:hidden"
                :disabled="cart.isEmpty" @click="cartOpen = true">
                <span
                    class="relative flex size-10 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                    <ShoppingCart class="size-5" />
                    <span v-if="cart.count"
                        class="tabular absolute -right-1 -top-1 flex min-w-5 items-center justify-center rounded-full bg-foreground px-1 font-mono text-[0.65rem] font-bold text-background">
                        {{ cart.count }}
                    </span>
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold leading-tight">
                        {{ cart.isEmpty ? 'Cart is empty' : 'View cart' }}
                    </span>
                    <span class="block truncate text-xs text-muted-foreground">
                        {{ cart.isEmpty ? 'Tap a product to start' : `${cart.count} item${cart.count === 1 ? '' : 's'}`
                        }}
                    </span>
                </span>

                <span class="tabular shrink-0 font-mono text-xl font-bold text-primary"> {{
                    formatMoney(cart.totals.total,
                    currency) }} </span>
            </button>
        </div>

        <!-- Cart + checkout, for phones. -->
        <Sheet v-model:open="cartOpen">
            <SheetContent side="bottom" class="flex h-[85dvh] flex-col rounded-t-2xl p-0 lg:hidden">
                <SheetHeader class="shrink-0 px-4 pt-4 text-left">
                    <SheetTitle class="sr-only">Cart</SheetTitle>
                </SheetHeader>

                <Cart :currency="currency" />
                <Checkout :currency="currency" @pay="startCheckout"
                    @pick-customer="((pickerAsksDeposit = false), (pickerOpen = true))" />
            </SheetContent>
        </Sheet>

        <CustomerPicker :open="pickerOpen" :with-deposit="pickerAsksDeposit" :total="cart.totals.total"
            :currency="currency"
            :preselected="cart.customerId ? { id: cart.customerId, name: cart.customerName ?? '', phone: null } : null"
            @close="pickerOpen = false" @pick="attachCustomer" @confirm="recordDebt" />

        <PaymentModal :open="paymentOpen" :total="cart.totals.total" :currency="currency" :busy="paying"
            :qr="feed?.settings.qr" :online="sync.online.value" :store-id="boot.store_id" :register-id="registerId"
            @close="paymentOpen = false" @confirm="completeSale" />

        <Transition enter-from-class="opacity-0 translate-y-2"
            enter-active-class="transition duration-200 ease-out-quint" leave-to-class="opacity-0"
            leave-active-class="transition duration-150">
            <div v-if="toast"
                class="pointer-events-none fixed bottom-5 left-1/2 z-[60] -translate-x-1/2 rounded-full px-5 py-2.5 text-sm font-medium shadow-lg"
                :class="toast.kind === 'ok' ? 'bg-primary text-primary-foreground' : 'bg-destructive text-destructive-foreground'"
                role="status">
                {{ toast.text }}
            </div>
        </Transition>
    </AppLayout>
</template>
