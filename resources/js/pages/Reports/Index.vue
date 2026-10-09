<script setup lang="ts">
import BreakdownBars from '@/components/charts/BreakdownBars.vue';
import SalesBarChart from '@/components/charts/SalesBarChart.vue';
import StatTile from '@/components/charts/StatTile.vue';
import DateRangePicker from '@/components/DateRangePicker.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { Boxes, Download, Receipt, TrendingUp } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    filters: { from: string; to: string };
    totals: { orders: number; sales: string; items: number; basket: string };
    byDay: { day: string; orders: number; sales: string }[];
    byProduct: { product_name: string; qty: number; revenue: string }[];
    byPayment: { method: string; count: number; amount: string }[];
}>();

const from = ref(props.filters.from);
const to = ref(props.filters.to);

/** Filters sit in one row above the charts and reload on change. */
watch([from, to], () => {
    router.get(route('reports.index'), { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true, replace: true });
});

function preset(days: number) {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - (days - 1));

    from.value = start.toISOString().slice(0, 10);
    to.value = end.toISOString().slice(0, 10);
}

const { money } = useCurrency();

/*
 * Fixed slot per tender type, so cash is always slot 0 no matter how the
 * takings rank on any given day. `capitalize` would render "qr" as "Qr",
 * which is wrong for an initialism.
 */
const METHOD_SLOT: Record<string, number> = { cash: 0, card: 1, qr: 2, credit: 3 };
const METHOD_LABEL: Record<string, string> = {
    cash: 'Cash',
    card: 'Card',
    qr: 'QR',
    credit: 'Credit',
};

const paymentRows = computed(() =>
    props.byPayment.map((row) => ({
        label: METHOD_LABEL[row.method] ?? row.method,
        value: Number(row.amount),
        meta: `${row.count}×`,
        slot: METHOD_SLOT[row.method] ?? 0,
    })),
);

const productRows = computed(() =>
    props.byProduct.slice(0, 8).map((row) => ({
        label: row.product_name,
        value: Number(row.revenue),
        meta: `${row.qty}`,
    })),
);

const exportUrl = computed(() => route('reports.export', { from: from.value, to: to.value }));
</script>

<template>
    <Head title="Reports" />

    <AppLayout :breadcrumbs="[{ title: 'Reports', href: '/reports' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader title="Reports" description="Bucketed by the day the sale actually happened, not the day it reached the server.">
                <template #actions>
                    <Button as-child variant="outline" class="press">
                        <a :href="exportUrl">
                            <Download class="size-4" />
                            CSV
                        </a>
                    </Button>
                </template>
            </PageHeader>

            <!-- Filters: one row, above the charts. -->
            <div class="animate-rise mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card p-3">
                <DateRangePicker v-model:from="from" v-model:to="to" placeholder="Pick a period" class="w-full sm:w-[16rem]" />

                <div class="ml-auto flex gap-1">
                    <button
                        v-for="p in [
                            { label: '7d', days: 7 },
                            { label: '30d', days: 30 },
                            { label: '90d', days: 90 },
                        ]"
                        :key="p.label"
                        type="button"
                        class="press h-9 rounded-md border border-border px-3 text-xs font-medium text-muted-foreground"
                        @click="preset(p.days)"
                    >
                        {{ p.label }}
                    </button>
                </div>
            </div>

            <!-- Two-up on a phone: four figures in two rows, not a tower. -->
            <div class="stagger grid grid-cols-2 gap-2 sm:gap-4 xl:grid-cols-4">
                <StatTile label="Sales" :value="money(totals.sales)" :icon="TrendingUp" />
                <StatTile label="Orders" :value="String(totals.orders)" :icon="Receipt" />
                <StatTile label="Average basket" :value="money(totals.basket)" :icon="Boxes" />
                <StatTile label="Items sold" :value="String(totals.items)" :icon="Boxes" />
            </div>

            <section class="animate-rise mt-4 rounded-xl border border-border bg-card p-4" style="animation-delay: 120ms">
                <h2 class="mb-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Sales by day</h2>
                <SalesBarChart :rows="byDay" :height="240" />
            </section>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <section class="animate-rise rounded-xl border border-border bg-card p-4" style="animation-delay: 160ms">
                    <h2 class="mb-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Payment methods</h2>
                    <!-- Categorical: each row is a different tender type. -->
                    <BreakdownBars :rows="paymentRows" categorical />
                </section>

                <section class="animate-rise rounded-xl border border-border bg-card p-4" style="animation-delay: 200ms">
                    <h2 class="mb-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Top products by revenue</h2>
                    <!-- One measure across many products: a single hue, ranked. -->
                    <BreakdownBars :rows="productRows" />
                </section>
            </div>

            <!-- The table view the contrast warning obliges, and the thing an
                 accountant actually wants to copy out. -->
            <section class="animate-rise mt-4 overflow-hidden rounded-xl border border-border bg-card" style="animation-delay: 240ms">
                <h2 class="border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    Sales by product
                </h2>

                <div v-if="byProduct.length" class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Product</TableHead>
                                <TableHead data-numeric class="text-right">Qty</TableHead>
                                <TableHead data-numeric class="text-right">Revenue</TableHead>
                            </TableRow>
                        </TableHeader>
                        <tbody class="[&_tr:last-child]:border-0">
                            <TableRow v-for="row in byProduct" :key="row.product_name">
                                <TableCell class="font-medium">{{ row.product_name }}</TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono">
                                    {{ row.qty }}
                                </TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono font-medium">
                                    {{ money(row.revenue) }}
                                </TableCell>
                            </TableRow>
                        </tbody>
                    </Table>
                </div>
                <p v-else class="px-4 py-10 text-center text-sm text-muted-foreground">No sales in this range.</p>
            </section>
        </div>
    </AppLayout>
</template>
