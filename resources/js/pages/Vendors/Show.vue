<script setup lang="ts">
import StatTile from '@/components/charts/StatTile.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import type { User, Vendor } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Boxes, PackageSearch, TrendingUp, Wallet } from 'lucide-vue-next';

type VendorProduct = {
    id: number;
    uuid: string;
    name: string;
    sku: string;
    unit: string;
    cost_price: string;
    sell_price: string;
    is_active: boolean;
    on_hand: number;
    sold: number;
    revenue: string;
};

const props = defineProps<{
    vendor: Vendor;
    products: VendorProduct[];
    users: Pick<User, 'id' | 'uuid' | 'name' | 'email' | 'role' | 'is_active'>[];
    totals: { products: number; on_hand: number; stock_value: string; sold: number; revenue: string };
    days: number;
    windows: number[];
}>();

const { money } = useCurrency();

function pick(days: number) {
    router.get(route('vendors.show', { vendor: props.vendor.uuid }), { days }, { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <Head :title="vendor.name" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Vendors', href: '/vendors' },
            { title: vendor.name, href: `/vendors/${vendor.uuid}` },
        ]"
    >
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader :title="vendor.name" :description="vendor.contact_name ?? undefined">
                <template #actions>
                    <Button as-child variant="ghost" class="press">
                        <Link :href="route('vendors.index')">
                            <ArrowLeft class="size-4" />
                            Back
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <div class="mb-4 flex justify-end gap-1">
                <Button
                    v-for="w in windows"
                    :key="w"
                    size="sm"
                    :variant="w === days ? 'default' : 'outline'"
                    class="press h-9 px-3 text-xs"
                    @click="pick(w)"
                >
                    {{ w }}d
                </Button>
            </div>

            <div class="stagger mb-4 grid grid-cols-2 gap-2 sm:gap-4 xl:grid-cols-4">
                <StatTile label="Products" :value="String(totals.products)" :icon="Boxes" />
                <StatTile
                    label="On hand"
                    :value="String(totals.on_hand)"
                    :icon="PackageSearch"
                    :hint="`Worth ${money(totals.stock_value)} at cost`"
                />
                <StatTile :label="`Sold · ${days}d`" :value="String(totals.sold)" :icon="Wallet" hint="Base units, packs included" />
                <StatTile :label="`Sales · ${days}d`" :value="money(totals.revenue)" :icon="TrendingUp" />
            </div>

            <div class="grid items-start gap-4 lg:grid-cols-3">
                <section class="animate-rise rounded-xl border border-border bg-card p-5">
                    <h2 class="mb-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Details</h2>
                    <dl class="grid gap-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Status</dt>
                            <dd>
                                <Badge :variant="vendor.is_active ? 'secondary' : 'outline'">{{ vendor.is_active ? 'Active' : 'Inactive' }}</Badge>
                            </dd>
                        </div>
                        <div v-if="vendor.phone">
                            <dt class="text-muted-foreground">Phone</dt>
                            <dd class="tabular font-mono">{{ vendor.phone }}</dd>
                        </div>
                        <div v-if="vendor.email">
                            <dt class="text-muted-foreground">Email</dt>
                            <dd>{{ vendor.email }}</dd>
                        </div>
                        <div v-if="vendor.address">
                            <dt class="text-muted-foreground">Address</dt>
                            <dd>{{ vendor.address }}</dd>
                        </div>
                        <div v-if="vendor.notes">
                            <dt class="text-muted-foreground">Notes</dt>
                            <dd class="whitespace-pre-line">{{ vendor.notes }}</dd>
                        </div>
                    </dl>

                    <h2 class="mb-3 mt-6 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Staff accounts</h2>
                    <ul v-if="users.length" class="grid gap-2 text-sm">
                        <li v-for="u in users" :key="u.id" class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate font-medium">{{ u.name }}</p>
                                <p class="truncate text-xs text-muted-foreground">{{ u.email }}</p>
                            </div>
                            <Badge :variant="u.is_active ? 'secondary' : 'outline'" class="shrink-0 capitalize">
                                {{ u.is_active ? u.role : 'inactive' }}
                            </Badge>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">None. Create one on the Staff screen with the Vendor role.</p>
                </section>

                <section
                    class="animate-rise min-w-0 overflow-hidden rounded-xl border border-border bg-card lg:col-span-2"
                    style="animation-delay: 60ms"
                >
                    <h2 class="p-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Products supplied</h2>

                    <div v-if="products.length" class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow class="hover:bg-transparent">
                                    <TableHead>Product</TableHead>
                                    <TableHead data-numeric class="text-right">Cost</TableHead>
                                    <TableHead data-numeric class="text-right">Price</TableHead>
                                    <TableHead data-numeric class="text-right">On hand</TableHead>
                                    <TableHead data-numeric class="text-right">Sold · {{ days }}d</TableHead>
                                    <TableHead data-numeric class="text-right">Sales · {{ days }}d</TableHead>
                                </TableRow>
                            </TableHeader>
                            <tbody class="[&_tr:last-child]:border-0">
                                <TableRow v-for="p in products" :key="p.id">
                                    <TableCell>
                                        <Link :href="route('products.show', { product: p.uuid })" class="font-medium hover:underline">{{
                                            p.name
                                        }}</Link>
                                        <p class="font-mono text-xs text-muted-foreground">{{ p.sku }}</p>
                                    </TableCell>
                                    <TableCell data-numeric class="tabular text-right font-mono">{{ money(p.cost_price) }}</TableCell>
                                    <TableCell data-numeric class="tabular text-right font-mono">{{ money(p.sell_price) }}</TableCell>
                                    <TableCell data-numeric class="tabular text-right font-mono" :class="p.on_hand < 0 && 'text-destructive'">
                                        {{ p.on_hand }}
                                    </TableCell>
                                    <TableCell data-numeric class="tabular text-right font-mono">{{ p.sold }}</TableCell>
                                    <TableCell data-numeric class="tabular text-right font-mono">{{ money(p.revenue) }}</TableCell>
                                </TableRow>
                            </tbody>
                        </Table>
                    </div>

                    <EmptyState
                        v-else
                        :icon="Boxes"
                        title="No products yet"
                        description="Pick this vendor on a product's edit page to link it here."
                    />
                </section>
            </div>
        </div>
    </AppLayout>
</template>
