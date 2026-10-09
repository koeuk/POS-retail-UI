<script setup lang="ts">
import StatTile from '@/components/charts/StatTile.vue';
import DateRangePicker from '@/components/DateRangePicker.vue';
import EmptyState from '@/components/EmptyState.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import { currentPerPage } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Search, Utensils } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface Row {
    id: number;
    uuid: string;
    order_no: string;
    total: string;
    created_at: string;
    created_offline_at: string | null;
    cashier: { id: number; name: string } | null;
    items: { id: number; product_name: string; qty: number }[];
}

const props = defineProps<{
    rows: Paginated<Row>;
    filters: { search: string; from: string; to: string };
    summary: { week: Spent; month: Spent; year: Spent };
}>();

interface Spent {
    count: number;
    value: string;
}

const { money } = useCurrency();

const times = (n: number) => `${n} time${n === 1 ? '' : 's'}, at shelf price`;

const search = ref(props.filters.search);
// The picker hands back undefined when a range is cleared; '' from the server means the same thing.
const from = ref<string | undefined>(props.filters.from || undefined);
const to = ref<string | undefined>(props.filters.to || undefined);
let debounce: ReturnType<typeof setTimeout>;

function reload() {
    router.get(
        route('consumption.index'),
        { filter: { search: search.value || undefined, from: from.value || undefined, to: to.value || undefined }, per_page: currentPerPage() },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(reload, 300);
});
watch([from, to], reload);

const when = (r: Row) => new Date(r.created_offline_at ?? r.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** "Cola 330ml ×2, Bar Soap" — the whole take on one line. */
const summarise = (r: Row) => r.items.map((i) => (i.qty > 1 ? `${i.product_name} ×${i.qty}` : i.product_name)).join(', ');
</script>

<template>
    <Head title="Myself" />

    <AppLayout :breadcrumbs="[{ title: 'Myself', href: '/consumption' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader
                title="Myself"
                description="Things you took for yourself. Stock goes down but nothing counts as a sale — the value shown is what it would have sold for."
            />

            <!-- Phone: a swipeable tile rail — three money figures cannot share
                 390px honestly. Desktop: the usual three-up grid. -->
            <div
                class="stagger scrollbar-none -mx-2.5 mb-4 flex gap-2 overflow-x-auto px-2.5 sm:mx-0 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0 [&>*]:min-w-[11rem] [&>*]:flex-1 sm:[&>*]:min-w-0"
            >
                <StatTile label="This week" :value="money(summary.week.value)" :icon="Utensils" :hint="times(summary.week.count)" />
                <StatTile label="This month" :value="money(summary.month.value)" :icon="Utensils" :hint="times(summary.month.count)" />
                <StatTile label="This year" :value="money(summary.year.value)" :icon="Utensils" :hint="times(summary.year.count)" />
            </div>

            <div class="animate-rise rounded-xl border border-border bg-card" style="animation-delay: 60ms">
                <div class="space-y-2 border-b border-border p-3 md:flex md:items-center md:gap-2 md:space-y-0">
                    <div class="relative md:flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Search by product…" class="h-10 rounded-full pl-9" autocomplete="off" />
                    </div>
                    <div class="scrollbar-none -mx-3 flex gap-2 overflow-x-auto px-3 py-2 md:m-0 md:overflow-visible md:p-0">
                        <DateRangePicker v-model:from="from" v-model:to="to" placeholder="Any date" class="h-9 shrink-0 rounded-full" />
                    </div>
                </div>

                <!-- Phone: one card per take. -->
                <ul v-if="rows.data.length" class="space-y-2 p-2.5 md:hidden">
                    <li v-for="r in rows.data" :key="r.id" class="overflow-hidden rounded-xl border border-border bg-card">
                        <Link :href="route('orders.show', { order: r.uuid })" class="row-press block px-3.5 py-3">
                            <div class="flex items-baseline justify-between gap-3">
                                <p class="min-w-0 flex-1 truncate font-medium leading-snug">{{ summarise(r) }}</p>
                                <Money :value="r.total" :muted="false" class="shrink-0 text-[0.95rem] font-semibold" />
                            </div>
                            <p class="tabular mt-1 truncate font-mono text-xs text-muted-foreground">{{ r.order_no }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ when(r) }} · {{ r.cashier?.name ?? '—' }}</p>
                        </Link>
                    </li>
                </ul>

                <div v-if="rows.data.length" class="hidden overflow-x-auto md:block">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>What</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>By</TableHead>
                                <TableHead data-numeric class="text-right">Value</TableHead>
                            </TableRow>
                        </TableHeader>
                        <tbody class="[&_tr:last-child]:border-0">
                            <TableRow v-for="r in rows.data" :key="r.id">
                                <TableCell>
                                    <Link
                                        :href="route('orders.show', { order: r.uuid })"
                                        class="block max-w-md truncate font-medium hover:underline"
                                        >{{ summarise(r) }}</Link
                                    >
                                    <p class="tabular font-mono text-xs text-muted-foreground">{{ r.order_no }}</p>
                                </TableCell>
                                <TableCell class="whitespace-nowrap text-sm text-muted-foreground">{{ when(r) }}</TableCell>
                                <TableCell class="text-sm text-muted-foreground">{{ r.cashier?.name ?? '—' }}</TableCell>
                                <TableCell data-numeric class="text-right"><Money :value="r.total" /></TableCell>
                            </TableRow>
                        </tbody>
                    </Table>
                </div>

                <EmptyState
                    v-else
                    :icon="Utensils"
                    title="Nothing taken"
                    description="Choose “Myself” at the till when you take something for yourself."
                />

                <Pagination :links="rows.links" :from="rows.from" :to="rows.to" :total="rows.total" :per-page="rows.per_page" />
            </div>
        </div>
    </AppLayout>
</template>
