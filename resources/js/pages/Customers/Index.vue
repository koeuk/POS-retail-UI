<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import HistoryButton from '@/components/HistoryButton.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { currentPerPage } from '@/lib/utils';
import type { Customer, Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2, UsersRound } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{
    customers: Paginated<Customer>;
    filters: { search?: string };
}>();

/* Controls the user cannot use are hidden; the policies are the wall. */
const { may } = usePermissions();

const search = ref(props.filters.search ?? '');
let debounce: ReturnType<typeof setTimeout>;

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            route('customers.index'),
            { filter: { search: search.value || undefined }, per_page: currentPerPage() },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

const editing = ref<Customer | null>(null);
const dialogOpen = ref(false);
const form = useForm({ name: '', phone: '', email: '', loyalty_points: 0 });

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(customer: Customer) {
    editing.value = customer;
    form.clearErrors();
    form.name = customer.name;
    form.phone = customer.phone ?? '';
    form.email = customer.email ?? '';
    form.loyalty_points = customer.loyalty_points;
    dialogOpen.value = true;
}

function submit() {
    const opts = { onSuccess: () => (dialogOpen.value = false), preserveScroll: true };

    if (editing.value) {
        form.put(route('customers.update', { customer: editing.value.uuid }), opts);
    } else {
        form.post(route('customers.store'), opts);
    }
}

const pendingDelete = ref<Customer | null>(null);

function confirmDelete() {
    if (!pendingDelete.value) return;
    router.delete(route('customers.destroy', { customer: pendingDelete.value.uuid }), {
        preserveScroll: true,
        onFinish: () => (pendingDelete.value = null),
    });
}
</script>

<template>
    <Head title="Customers" />

    <AppLayout :breadcrumbs="[{ title: 'Customers', href: '/customers' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader title="Customers" description="Optional at the till — attach a customer to a sale to track loyalty points.">
                <template #actions>
                    <Button v-if="may('customers', 'create')" class="press" @click="openCreate">
                        <Plus class="size-4" />
                        New customer
                    </Button>
                </template>
            </PageHeader>

            <div class="list-panel animate-rise" style="animation-delay: 60ms">
                <div class="border-b border-border p-3">
                    <div class="relative md:max-w-sm">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Search name, phone or email…" class="h-10 rounded-full pl-9" autocomplete="off" />
                    </div>
                </div>

                <div v-if="customers.data.length" class="hidden overflow-x-auto md:block">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Customer</TableHead>
                                <TableHead>Contact</TableHead>
                                <TableHead data-numeric class="text-right">Orders</TableHead>
                                <TableHead data-numeric class="text-right">Spent</TableHead>
                                <TableHead data-numeric class="text-right">Points</TableHead>
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
                            <TableRow v-for="c in customers.data" :key="c.id" class="group">
                                <TableCell class="font-medium">{{ c.name }}</TableCell>
                                <TableCell class="text-sm text-muted-foreground">
                                    <span v-if="c.phone" class="tabular font-mono">{{ c.phone }}</span>
                                    <span v-if="c.phone && c.email"> · </span>
                                    <span v-if="c.email">{{ c.email }}</span>
                                    <span v-if="!c.phone && !c.email">—</span>
                                </TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono">
                                    {{ c.orders_count ?? 0 }}
                                </TableCell>
                                <TableCell data-numeric class="text-right">
                                    <Money :value="c.spent_total ?? 0" />
                                </TableCell>
                                <TableCell data-numeric class="text-right">
                                    <Badge v-if="c.loyalty_points > 0" variant="secondary" class="tabular font-mono">
                                        {{ c.loyalty_points }}
                                    </Badge>
                                    <span v-else class="text-muted-foreground">—</span>
                                </TableCell>
                                <TableCell>
                                    <div class="flex items-center gap-1">
                                        <HistoryButton subject-type="Customer" :subject-id="c.uuid" :label="c.name" />
                                        <Button
                                            v-if="may('customers', 'update')"
                                            variant="ghost"
                                            size="icon"
                                            class="press size-8"
                                            aria-label="Edit"
                                            @click="openEdit(c)"
                                        >
                                            <Pencil class="size-4" />
                                        </Button>
                                        <Button
                                            v-if="may('customers', 'delete')"
                                            variant="ghost"
                                            size="icon"
                                            class="press size-8 text-muted-foreground hover:text-destructive"
                                            aria-label="Delete"
                                            @click="pendingDelete = c"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TransitionGroup>
                    </Table>
                </div>

                <ul v-if="customers.data.length" class="md:hidden">
                    <li v-for="c in customers.data" :key="c.id" class="list-row">
                        <button type="button" class="list-row-main" :aria-label="`Edit ${c.name}`" @click="openEdit(c)">
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium leading-tight">{{ c.name }}</p>
                                <p class="truncate text-xs text-muted-foreground">
                                    <span v-if="c.phone" class="tabular font-mono">{{ c.phone }}</span>
                                    <span v-if="c.phone && c.email"> · </span>
                                    <span v-if="c.email">{{ c.email }}</span>
                                    <span v-if="!c.phone && !c.email">No contact details</span>
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="font-medium leading-tight"><Money :value="c.spent_total ?? 0" /></p>
                                <p class="tabular font-mono text-xs text-muted-foreground">
                                    {{ c.orders_count ?? 0 }} order{{ c.orders_count === 1 ? '' : 's' }}
                                    <span v-if="c.loyalty_points > 0" class="text-primary"> · {{ c.loyalty_points }} pts</span>
                                </p>
                            </div>
                        </button>

                        <button
                            v-if="may('customers', 'delete')"
                            type="button"
                            class="list-row-action"
                            :aria-label="`Delete ${c.name}`"
                            @click="pendingDelete = c"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </li>
                </ul>

                <EmptyState
                    v-else
                    :icon="UsersRound"
                    title="No customers yet"
                    description="Customers are optional — most sales are anonymous walk-ins."
                >
                    <Button variant="outline" class="press" @click="openCreate">Add a customer</Button>
                </EmptyState>

                <Pagination
                    :links="customers.links"
                    :from="customers.from"
                    :to="customers.to"
                    :total="customers.total"
                    :per-page="customers.per_page"
                />
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <form @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{ editing ? 'Edit customer' : 'New customer' }}</DialogTitle>
                        <DialogDescription>Only a name is required.</DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-2">
                            <Label for="cust-name">Name</Label>
                            <Input id="cust-name" v-model="form.name" required autofocus />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="cust-phone">Phone</Label>
                                <Input id="cust-phone" v-model="form.phone" class="font-mono" />
                                <InputError :message="form.errors.phone" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="cust-points">Loyalty points</Label>
                                <Input id="cust-points" v-model="form.loyalty_points" type="number" min="0" class="tabular font-mono" />
                                <InputError :message="form.errors.loyalty_points" />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label for="cust-email">Email</Label>
                            <Input id="cust-email" v-model="form.email" type="email" />
                            <InputError :message="form.errors.email" />
                        </div>
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
                        A customer with order history cannot be deleted — the sales records must keep pointing at a real row.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" class="press" @click="pendingDelete = null">Cancel</Button>
                    <Button class="press bg-destructive text-destructive-foreground hover:bg-destructive/90" @click="confirmDelete"> Delete </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
