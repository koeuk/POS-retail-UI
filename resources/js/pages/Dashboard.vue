<script setup lang="ts">
import SalesBarChart from '@/components/charts/SalesBarChart.vue';
import StatTile from '@/components/charts/StatTile.vue';
import DatePicker from '@/components/DatePicker.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useCurrency } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    ChartNoAxesColumn,
    ChevronRight,
    CloudOff,
    HandCoins,
    PackageSearch,
    Receipt,
    ScanBarcode,
    Shapes,
    TriangleAlert,
    Utensils,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Summary {
    sales: string;
    orders: number;
    basket: string;
    items: number;
}

interface StockRow {
    id: number;
    qty: number;
    low_stock_threshold: number | null;
    product: { id: number; name: string; unit: string } | null;
    store: { id: number; name: string } | null;
}

interface Spent {
    count: number;
    value: string;
}

const props = defineProps<{
    today: Summary;
    yesterday: Summary;
    trend: { day: string; orders: number; sales: string }[];
    lowStock: StockRow[];
    oversold: StockRow[];
    recentOrders: {
        id: number;
        order_no: string;
        total: string;
        created_offline_at: string | null;
        cashier: { id: number; name: string } | null;
    }[];
    offlineToday: number;
    debts: { count: number; owed: string };
    myself: { week: Spent; month: Spent; year: Spent };
    catalogue: { products: number; categories: number };
    canSeeReports: boolean;
    filters: { date: string; isToday: boolean };
}>();

/*
 * The dashboard answers "how was the day?" — this picks which day. Today by
 * default; any past day reloads the same screen for that date.
 */
const date = ref(props.filters.date);
const maxDate = new Date().toISOString().slice(0, 10);

watch(date, (value) => {
    if (!value) return;
    router.get(route('dashboard'), value === maxDate ? {} : { date: value }, { preserveState: true, preserveScroll: true, replace: true });
});

const heroLabel = computed(() =>
    props.filters.isToday ? "Today's sales" : `Sales · ${new Date(props.filters.date).toLocaleDateString(undefined, { dateStyle: 'medium' })}`,
);

const times = (n: number) => `${n} time${n === 1 ? '' : 's'}, at shelf price`;

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);

/** "Good morning" / "Good afternoon" / "Good evening", by the clock. */
const greeting = computed(() => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
});

const { money } = useCurrency();

const time = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '—');

const weekTotal = computed(() => props.trend.reduce((sum, row) => sum + Number(row.sales), 0));

/** Change against yesterday, for the hero chip. Null when there is no base. */
const salesDelta = computed(() => {
    const now = Number(props.today.sales);
    const before = Number(props.yesterday.sales);
    if (!Number.isFinite(before) || before === 0) return null;
    return ((now - before) / before) * 100;
});

/*
 * The four places a shopkeeper actually goes from here. Rendered as a row of
 * round buttons inside the hero on a phone — a thumb reaches them without
 * scrolling, which a list of full-width cards never manages.
 */
const quickActions = computed(() =>
    [
        { title: 'Sell', href: '/pos', icon: ScanBarcode, chip: 'bg-chart-1/10 text-chart-1', always: true },
        { title: 'Orders', href: '/orders', icon: Receipt, chip: 'bg-chart-2/15 text-chart-2', always: props.canSeeReports },
        { title: 'Stock', href: '/inventory', icon: PackageSearch, chip: 'bg-chart-5/10 text-chart-5', always: props.canSeeReports },
        { title: 'Reports', href: '/reports', icon: ChartNoAxesColumn, chip: 'bg-chart-4/15 text-chart-4', always: props.canSeeReports },
    ].filter((a) => a.always),
);
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="[{ title: 'Dashboard', href: '/dashboard' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader description="Today across the shop floor.">
                <!-- Soft hello, bold name: the greeting is furniture, the name is the title. -->
                <template #title>
                    <span class="font-normal text-muted-foreground">{{ greeting }},</span>
                    {{ user?.name ?? 'Dashboard' }}
                </template>
                <template #actions>
                    <DatePicker v-model="date" aria-label="Show figures for a day" class="h-10 rounded-full" />
                </template>
            </PageHeader>

            <!--
                The hero, every screen size. One painted card carrying the only
                number that matters before lunch, with the day's routes out of
                it — on a phone they sit under the number for the thumb, on a
                desk they line up beside it. The gradient is the shopfront's
                paint catching light, not flat ink.
            -->
            <section
                class="surface-brand animate-rise mb-4 rounded-3xl p-5 text-brand-foreground md:mb-6 md:flex md:items-center md:justify-between md:gap-8 md:p-7"
            >
                <div>
                    <p class="font-mono text-[0.65rem] uppercase tracking-[0.18em] opacity-85">{{ heroLabel }}</p>

                    <div class="mt-1 flex items-end gap-2">
                        <p class="tabular font-mono text-4xl font-bold leading-none md:text-5xl">{{ money(today.sales) }}</p>
                        <span
                            v-if="salesDelta !== null"
                            class="mb-0.5 rounded-full px-2 py-0.5 text-[0.7rem] font-semibold"
                            :class="
                                salesDelta >= 0 ? 'bg-brand-foreground/20 text-brand-foreground' : 'bg-brand-foreground/10 text-brand-foreground/80'
                            "
                        >
                            {{ salesDelta >= 0 ? '+' : '' }}{{ salesDelta.toFixed(0) }}%
                        </span>
                    </div>

                    <p class="tabular mt-1.5 font-mono text-[0.8125rem] opacity-90 sm:text-sm md:text-lg">
                        {{ today.orders }} order{{ today.orders === 1 ? '' : 's' }} · {{ today.items }} item{{ today.items === 1 ? '' : 's' }}
                        <span v-if="offlineToday > 0"> · {{ offlineToday }} synced offline</span>
                    </p>
                </div>

                <div class="hidden items-start gap-2 md:flex md:justify-end md:gap-7">
                    <Link
                        v-for="action in quickActions"
                        :key="action.href"
                        :href="action.href"
                        class="press flex flex-col items-center gap-1.5 md:flex-none"
                    >
                        <span
                            class="flex size-12 items-center justify-center rounded-full bg-brand-foreground/15 transition-colors hover:bg-brand-foreground/25"
                        >
                            <component :is="action.icon" class="size-5" />
                        </span>
                        <span class="text-[0.7rem] font-medium opacity-80">{{ action.title }}</span>
                    </Link>
                </div>
            </section>

            <!-- Phone: the shortcuts live in their own card under the figure,
                 so the green banner stays a single clean number. -->
            <nav class="animate-rise mb-4 flex items-stretch gap-2 md:hidden" style="animation-delay: 40ms" aria-label="Quick actions">
                <Link
                    v-for="action in quickActions"
                    :key="action.href"
                    :href="action.href"
                    class="press flex flex-1 flex-col items-center gap-1.5 rounded-2xl border border-border bg-card px-2 py-3"
                >
                    <span class="flex size-11 items-center justify-center rounded-full" :class="action.chip">
                        <component :is="action.icon" class="size-5" />
                    </span>
                    <span class="text-[0.7rem] font-medium text-muted-foreground">{{ action.title }}</span>
                </Link>
            </nav>

            <!-- Phone summary: one swipeable rail, bleeding to the screen
                 edges — the half-visible card is the scroll affordance.
                 Desktop shows these figures in the wider tile rows below. -->
            <div v-if="canSeeReports" class="stagger scrollbar-none mb-4 flex snap-x snap-proximity gap-2 overflow-x-auto rounded-2xl md:hidden">
                <StatTile class="w-44 shrink-0 snap-start" label="Products" :value="String(catalogue.products)" :icon="Boxes" :accent="1" />
                <StatTile class="w-44 shrink-0 snap-start" label="Categories" :value="String(catalogue.categories)" :icon="Shapes" :accent="2" />
                <StatTile
                    class="w-44 shrink-0 snap-start"
                    label="In debt"
                    :value="money(debts.owed)"
                    :icon="HandCoins"
                    :tone="debts.count > 0 ? 'warning' : 'default'"
                    :hint="`${debts.count} sale${debts.count === 1 ? '' : 's'} on credit`"
                />
                <StatTile
                    class="w-44 shrink-0 snap-start"
                    label="Myself · month"
                    :value="money(myself.month.value)"
                    :icon="Utensils"
                    :accent="4"
                    :hint="times(myself.month.count)"
                />
            </div>

            <div class="stagger hidden gap-4 md:grid md:grid-cols-3">
                <StatTile label="Orders" :value="String(today.orders)" :icon="Receipt" :accent="2" :previous="yesterday.orders" />
                <StatTile
                    label="Average basket"
                    :value="money(today.basket)"
                    :icon="Boxes"
                    :accent="1"
                    :hint="`${today.items} item${today.items === 1 ? '' : 's'} sold`"
                />
                <StatTile
                    label="Synced from offline"
                    :value="String(offlineToday)"
                    :icon="CloudOff"
                    :accent="5"
                    hint="Sales rung up without a connection"
                />
            </div>

            <!-- The owner's own money: what is still out on credit, and what
                 the shop fed its keeper. Manager eyes only, like the pages. -->
            <div v-if="canSeeReports" class="stagger mt-4 hidden gap-4 md:grid md:grid-cols-2 xl:grid-cols-4">
                <StatTile
                    label="Owed to you"
                    :value="money(debts.owed)"
                    :icon="HandCoins"
                    :tone="debts.count > 0 ? 'warning' : 'default'"
                    :hint="`${debts.count} sale${debts.count === 1 ? '' : 's'} on credit, not yet paid`"
                />
                <StatTile label="Myself · week" :value="money(myself.week.value)" :icon="Utensils" :accent="4" :hint="times(myself.week.count)" />
                <StatTile label="Myself · month" :value="money(myself.month.value)" :icon="Utensils" :accent="4" :hint="times(myself.month.count)" />
                <StatTile label="Myself · year" :value="money(myself.year.value)" :icon="Utensils" :accent="4" :hint="times(myself.year.count)" />
            </div>

            <!-- items-start: panels size to their own content rather than
                 stretching to the tallest column, which left the chart sitting
                 above a block of dead space. -->
            <div class="mt-4 grid items-start gap-4 lg:grid-cols-3">
                <!-- One series, so no legend: the panel title names it. -->
                <section
                    class="animate-rise min-w-0 rounded-2xl border border-border bg-card p-4 lg:col-span-2"
                    style="animation-delay: 120ms"
                >
                    <div class="mb-3 flex items-baseline justify-between">
                        <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Sales · last 7 days</h2>
                        <p class="tabular font-mono text-sm font-semibold">{{ money(weekTotal) }}</p>
                    </div>

                    <SalesBarChart :rows="trend" :height="200" />
                </section>

                <section class="animate-rise rounded-2xl border border-border bg-card" style="animation-delay: 160ms">
                    <div class="flex items-center justify-between border-b border-border px-4 py-3">
                        <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Latest sales</h2>
                        <Link
                            v-if="canSeeReports"
                            :href="route('orders.index')"
                            class="press flex items-center gap-0.5 text-xs font-medium text-primary"
                        >
                            View all
                            <ChevronRight class="size-3.5" />
                        </Link>
                    </div>

                    <ul v-if="recentOrders.length" class="divide-y divide-border">
                        <li v-for="order in recentOrders" :key="order.id" class="flex items-center gap-3 px-4 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="tabular truncate font-mono text-xs font-medium">{{ order.order_no }}</p>
                                <p class="truncate text-[0.7rem] text-muted-foreground">
                                    {{ order.cashier?.name ?? 'Unknown' }} · {{ time(order.created_offline_at) }}
                                </p>
                            </div>
                            <Money :value="order.total" :muted="false" />
                        </li>
                    </ul>
                    <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">No sales yet today.</p>
                </section>
            </div>

            <div class="mt-4 grid items-start gap-4 lg:grid-cols-2">
                <!--
                    Oversold is the reconciliation list: stock driven below zero
                    by offline sales that synced after the shelf was empty. It is
                    the deliberate cost of never rejecting a completed sale.
                -->
                <section
                    v-if="oversold.length"
                    class="animate-rise rounded-2xl border border-destructive/40 bg-card"
                    style="animation-delay: 200ms"
                >
                    <h2
                        class="flex items-center gap-2 border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-destructive"
                    >
                        <TriangleAlert class="size-4" />
                        Oversold — needs reconciling
                    </h2>
                    <ul class="divide-y divide-border">
                        <li v-for="row in oversold" :key="row.id" class="flex items-center gap-3 px-4 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ row.product?.name }}</p>
                                <p class="truncate text-[0.7rem] text-muted-foreground">{{ row.store?.name }}</p>
                            </div>
                            <span class="tabular font-mono text-sm font-semibold text-destructive"> {{ row.qty }} {{ row.product?.unit }} </span>
                        </li>
                    </ul>
                </section>

                <section class="animate-rise rounded-2xl border border-border bg-card" style="animation-delay: 240ms">
                    <h2 class="border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Low stock
                    </h2>
                    <ul v-if="lowStock.length" class="divide-y divide-border">
                        <li v-for="row in lowStock" :key="row.id" class="flex items-center gap-3 px-4 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ row.product?.name }}</p>
                                <p class="truncate text-[0.7rem] text-muted-foreground">{{ row.store?.name }}</p>
                            </div>
                            <Badge variant="outline" class="tabular font-mono"> {{ row.qty }} / {{ row.low_stock_threshold }} </Badge>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">Everything is above its threshold.</p>
                </section>
            </div>

            <div v-if="canSeeReports" class="mt-4 flex justify-center">
                <Button as-child variant="outline" class="press">
                    <Link :href="route('reports.index')">Full reports</Link>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
