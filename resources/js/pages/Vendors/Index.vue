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
import { Switch } from '@/components/ui/switch';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useCurrency } from '@/composables/useCurrency';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { currentPerPage } from '@/lib/utils';
import type { Paginated, Vendor } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Boxes, CheckCircle2, Pencil, Plus, Search, Trash2, TrendingUp, Truck } from 'lucide-vue-next';
import { ref, watch } from 'vue';

type VendorSales = { orders: number; qty: number; revenue: string };
type VendorRow = Vendor & { sales: VendorSales };

const props = defineProps<{
    vendors: Paginated<VendorRow>;
    summary: { vendors: number; active: number; products: number; revenue: string };
    days: number;
    windows: number[];
    filters: { search?: string };
}>();

/* Controls the user cannot use are hidden; the policies are the wall. */
const { may } = usePermissions();
const { money } = useCurrency();

const search = ref(props.filters.search ?? '');
let debounce: ReturnType<typeof setTimeout>;

function reload(extra: Record<string, unknown> = {}) {
    router.get(
        route('vendors.index'),
        { filter: { search: search.value || undefined }, days: props.days, per_page: currentPerPage(), ...extra },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => reload(), 300);
});

const editing = ref<Vendor | null>(null);
const dialogOpen = ref(false);
const form = useForm({ name: '', contact_name: '', phone: '', email: '', address: '', notes: '', is_active: true as boolean });

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(vendor: Vendor) {
    editing.value = vendor;
    form.clearErrors();
    form.name = vendor.name;
    form.contact_name = vendor.contact_name ?? '';
    form.phone = vendor.phone ?? '';
    form.email = vendor.email ?? '';
    form.address = vendor.address ?? '';
    form.notes = vendor.notes ?? '';
    form.is_active = vendor.is_active;
    dialogOpen.value = true;
}

function submit() {
    const opts = { onSuccess: () => (dialogOpen.value = false), preserveScroll: true };

    if (editing.value) {
        form.put(route('vendors.update', { vendor: editing.value.uuid }), opts);
    } else {
        form.post(route('vendors.store'), opts);
    }
}

const pendingDelete = ref<Vendor | null>(null);

function confirmDelete() {
    if (!pendingDelete.value) return;
    router.delete(route('vendors.destroy', { vendor: pendingDelete.value.uuid }), {
        preserveScroll: true,
        onFinish: () => (pendingDelete.value = null),
    });
}
</script>

<template>
    <Head title="Vendors" />

    <AppLayout :breadcrumbs="[{ title: 'Vendors', href: '/vendors' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader title="Vendors" description="The suppliers you buy from — what each one supplies and how it sells.">
                <template #actions>
                    <Button v-if="may('vendors', 'create')" class="press" @click="openCreate">
                        <Plus class="size-4" />
                        New vendor
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
                    @click="reload({ days: w })"
                >
                    {{ w }}d
                </Button>
            </div>

            <div class="stagger mb-4 grid grid-cols-2 gap-2 sm:gap-4 xl:grid-cols-4">
                <StatTile label="Vendors" :value="String(summary.vendors)" :icon="Truck" />
                <StatTile label="Active" :value="String(summary.active)" :icon="CheckCircle2" />
                <StatTile label="Products supplied" :value="String(summary.products)" :icon="Boxes" />
                <StatTile :label="`Sales · ${days}d`" :value="money(summary.revenue)" :icon="TrendingUp" />
            </div>

            <div class="list-panel animate-rise" style="animation-delay: 60ms">
                <div class="border-b border-border p-3">
                    <div class="relative md:max-w-sm">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Search name, contact or phone…" class="h-10 rounded-full pl-9" autocomplete="off" />
                    </div>
                </div>

                <div v-if="vendors.data.length" class="hidden overflow-x-auto md:block">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Vendor</TableHead>
                                <TableHead>Contact</TableHead>
                                <TableHead data-numeric class="text-right">Products</TableHead>
                                <TableHead data-numeric class="text-right">Staff</TableHead>
                                <TableHead data-numeric class="text-right">Sold · {{ days }}d</TableHead>
                                <TableHead data-numeric class="text-right">Sales · {{ days }}d</TableHead>
                                <TableHead class="w-[1%]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <tbody class="[&_tr:last-child]:border-0">
                            <TableRow v-for="v in vendors.data" :key="v.id" class="group">
                                <TableCell>
                                    <Link :href="route('vendors.show', { vendor: v.uuid })" class="font-medium hover:underline">{{ v.name }}</Link>
                                    <Badge v-if="!v.is_active" variant="outline" class="ml-2">Inactive</Badge>
                                </TableCell>
                                <TableCell class="text-sm text-muted-foreground">
                                    <span v-if="v.contact_name">{{ v.contact_name }}</span>
                                    <span v-if="v.contact_name && v.phone"> · </span>
                                    <span v-if="v.phone" class="tabular font-mono">{{ v.phone }}</span>
                                    <span v-if="!v.contact_name && !v.phone">—</span>
                                </TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono">{{ v.products_count ?? 0 }}</TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono">{{ v.users_count ?? 0 }}</TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono">{{ v.sales.qty }}</TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono">{{ money(v.sales.revenue) }}</TableCell>
                                <TableCell>
                                    <div class="flex items-center gap-1">
                                        <HistoryButton subject-type="Vendor" :subject-id="v.uuid" :label="v.name" />
                                        <Button
                                            v-if="may('vendors', 'update')"
                                            variant="ghost"
                                            size="icon"
                                            class="press size-8"
                                            aria-label="Edit"
                                            @click="openEdit(v)"
                                        >
                                            <Pencil class="size-4" />
                                        </Button>
                                        <Button
                                            v-if="may('vendors', 'delete')"
                                            variant="ghost"
                                            size="icon"
                                            class="press size-8 text-muted-foreground hover:text-destructive"
                                            aria-label="Delete"
                                            @click="pendingDelete = v"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </tbody>
                    </Table>
                </div>

                <ul v-if="vendors.data.length" class="md:hidden">
                    <li v-for="v in vendors.data" :key="v.id" class="list-row">
                        <Link :href="route('vendors.show', { vendor: v.uuid })" class="list-row-main">
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium leading-tight">
                                    {{ v.name }}
                                    <span v-if="!v.is_active" class="text-xs font-normal text-muted-foreground">· inactive</span>
                                </p>
                                <p class="truncate text-xs text-muted-foreground">
                                    {{ v.products_count ?? 0 }} product{{ v.products_count === 1 ? '' : 's' }}
                                    <span v-if="v.phone">
                                        · <span class="tabular font-mono">{{ v.phone }}</span></span
                                    >
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="tabular font-mono font-medium leading-tight">{{ money(v.sales.revenue) }}</p>
                                <p class="tabular font-mono text-xs text-muted-foreground">{{ v.sales.qty }} sold</p>
                            </div>
                        </Link>
                    </li>
                </ul>

                <EmptyState v-else :icon="Truck" title="No vendors yet" description="Add the suppliers you buy from, then pick one on each product.">
                    <Button v-if="may('vendors', 'create')" variant="outline" class="press" @click="openCreate">Add a vendor</Button>
                </EmptyState>

                <Pagination :links="vendors.links" :from="vendors.from" :to="vendors.to" :total="vendors.total" :per-page="vendors.per_page" />
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <form @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{ editing ? 'Edit vendor' : 'New vendor' }}</DialogTitle>
                        <DialogDescription>Only a name is required.</DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-2">
                            <Label for="v-name">Name</Label>
                            <Input id="v-name" v-model="form.name" required autofocus />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="v-contact">Contact person</Label>
                                <Input id="v-contact" v-model="form.contact_name" />
                                <InputError :message="form.errors.contact_name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="v-phone">Phone</Label>
                                <Input id="v-phone" v-model="form.phone" class="font-mono" />
                                <InputError :message="form.errors.phone" />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label for="v-email">Email</Label>
                            <Input id="v-email" v-model="form.email" type="email" />
                            <InputError :message="form.errors.email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="v-address">Address</Label>
                            <Input id="v-address" v-model="form.address" />
                            <InputError :message="form.errors.address" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="v-notes">Notes</Label>
                            <Textarea id="v-notes" v-model="form.notes" rows="2" placeholder="Delivery days, payment terms…" />
                            <InputError :message="form.errors.notes" />
                        </div>
                        <label class="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2.5">
                            <span class="text-sm font-medium">Active</span>
                            <Switch v-model="form.is_active" />
                        </label>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="dialogOpen = false">Cancel</Button>
                        <Button type="submit" class="press" :disabled="form.processing">
                            {{ editing ? 'Save' : 'Create' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="!!pendingDelete" @update:open="(v) => !v && (pendingDelete = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete “{{ pendingDelete?.name }}”?</DialogTitle>
                    <DialogDescription>
                        Its products stay in the catalogue without a vendor. A vendor that still has staff accounts cannot be deleted — mark it
                        inactive instead.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" class="press" @click="pendingDelete = null">Cancel</Button>
                    <Button class="press bg-destructive text-destructive-foreground hover:bg-destructive/90" @click="confirmDelete">Delete</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
