<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import HistoryButton from '@/components/HistoryButton.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Register, Store } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { MapPin, Monitor, Phone, Plus, Store as StoreIcon, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineProps<{
    stores: Store[];
    canManage: boolean;
}>();

/*
 * Deleting a store is refused for three different reasons, each of which is
 * worth reading, so the message comes back as a validation error rather than
 * a toast that vanishes.
 */
const page = usePage();
const deleteError = computed(() => (page.props.errors as Record<string, string>)?.store ?? null);

const pendingDelete = ref<Store | null>(null);

function confirmDelete() {
    if (!pendingDelete.value) return;

    router.delete(route('stores.destroy', { store: pendingDelete.value.uuid }), {
        preserveScroll: true,
        onFinish: () => (pendingDelete.value = null),
    });
}

const storeDialog = ref(false);
const editingStore = ref<Store | null>(null);
const storeForm = useForm({ name: '', address: '', phone: '' });

function openStore(store: Store | null) {
    editingStore.value = store;
    storeForm.clearErrors();
    storeForm.name = store?.name ?? '';
    storeForm.address = store?.address ?? '';
    storeForm.phone = store?.phone ?? '';
    storeDialog.value = true;
}

function submitStore() {
    const opts = { onSuccess: () => (storeDialog.value = false), preserveScroll: true };

    if (editingStore.value) {
        storeForm.put(route('stores.update', { store: editingStore.value.uuid }), opts);
    } else {
        storeForm.post(route('stores.store'), opts);
    }
}

const registerDialog = ref(false);
const registerStore = ref<Store | null>(null);
const editingRegister = ref<Register | null>(null);
const registerForm = useForm({ name: '', is_active: true as boolean });

function openRegister(store: Store, register: Register | null) {
    registerStore.value = store;
    editingRegister.value = register;
    registerForm.clearErrors();
    registerForm.name = register?.name ?? '';
    registerForm.is_active = register?.is_active ?? true;
    registerDialog.value = true;
}

function submitRegister() {
    if (!registerStore.value) return;
    const opts = { onSuccess: () => (registerDialog.value = false), preserveScroll: true };

    if (editingRegister.value) {
        registerForm.put(
            route('stores.registers.update', {
                store: registerStore.value.uuid,
                register: editingRegister.value.uuid,
            }),
            opts,
        );
    } else {
        registerForm.post(route('stores.registers.store', { store: registerStore.value.uuid }), opts);
    }
}
</script>

<template>
    <Head title="Stores" />

    <AppLayout :breadcrumbs="[{ title: 'Stores', href: '/stores' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader title="Stores" description="Each store holds its own stock. Registers identify which terminal rang up a sale.">
                <template #actions>
                    <Button v-if="canManage" class="press" @click="openStore(null)">
                        <Plus class="size-4" />
                        New store
                    </Button>
                </template>
            </PageHeader>

            <div
                v-if="deleteError"
                class="animate-rise mb-4 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
            >
                {{ deleteError }}
            </div>

            <div v-if="stores.length" class="stagger grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <article v-for="store in stores" :key="store.id" class="lift rounded-xl border border-border bg-card p-4 md:p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <StoreIcon class="size-4" />
                            </div>
                            <div>
                                <h2 class="font-display text-lg font-semibold leading-tight">{{ store.name }}</h2>
                                <p class="tabular font-mono text-xs text-muted-foreground">
                                    {{ store.orders_count ?? 0 }} orders · {{ store.users_count ?? 0 }} staff
                                </p>
                            </div>
                        </div>
                        <div v-if="canManage" class="flex shrink-0 items-center gap-1">
                            <HistoryButton subject-type="Store" :subject-id="store.uuid" :label="store.name" />
                            <Button variant="ghost" size="sm" class="press touch-target" @click="openStore(store)"> Edit </Button>
                            <!--
                                Disabled rather than hidden when it is the only
                                store: a control that vanishes makes people hunt
                                for it, whereas a dead one with a reason attached
                                answers the question on the spot.
                            -->
                            <Button
                                variant="ghost"
                                size="icon"
                                class="press touch-target size-8 text-muted-foreground enabled:hover:text-destructive disabled:opacity-40"
                                :disabled="stores.length <= 1"
                                :aria-label="`Delete ${store.name}`"
                                :title="stores.length <= 1 ? 'This is the only store — the app needs at least one' : `Delete ${store.name}`"
                                @click="pendingDelete = store"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </div>

                    <dl class="mt-4 space-y-1.5 text-sm text-muted-foreground">
                        <div v-if="store.address" class="flex items-start gap-2">
                            <MapPin class="mt-0.5 size-3.5 shrink-0" />
                            <dd>{{ store.address }}</dd>
                        </div>
                        <div v-if="store.phone" class="flex items-center gap-2">
                            <Phone class="size-3.5 shrink-0" />
                            <dd class="tabular font-mono">{{ store.phone }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4 border-t border-border pt-4">
                        <div class="mb-2 flex items-center justify-between">
                            <h3 class="font-mono text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">Registers</h3>
                            <Button
                                v-if="canManage"
                                variant="ghost"
                                size="sm"
                                class="press touch-target h-7 px-2 text-xs"
                                @click="openRegister(store, null)"
                            >
                                <Plus class="size-3" />
                                Add
                            </Button>
                        </div>

                        <ul v-if="store.registers?.length" class="space-y-1">
                            <li
                                v-for="reg in store.registers"
                                :key="reg.id"
                                class="flex items-center gap-2 rounded-md px-2 py-2 text-sm transition-colors hover:bg-muted/50 md:py-1.5"
                            >
                                <Monitor class="size-3.5 shrink-0 text-muted-foreground" />
                                <span class="flex-1 truncate">{{ reg.name }}</span>
                                <Badge :variant="reg.is_active ? 'secondary' : 'outline'" class="text-[0.65rem]">
                                    {{ reg.is_active ? 'Active' : 'Off' }}
                                </Badge>
                                <Button
                                    v-if="canManage"
                                    variant="ghost"
                                    size="sm"
                                    class="press touch-target h-6 shrink-0 px-1.5 text-xs"
                                    @click="openRegister(store, reg)"
                                >
                                    Edit
                                </Button>
                            </li>
                        </ul>
                        <p v-else class="px-2 py-1.5 text-sm text-muted-foreground">No registers yet.</p>
                    </div>
                </article>
            </div>

            <EmptyState
                v-else
                :icon="StoreIcon"
                title="No stores yet"
                description="A store is required before anything can be sold — stock and orders both hang off it."
            >
                <Button v-if="canManage" variant="outline" class="press" @click="openStore(null)"> Add a store </Button>
            </EmptyState>
        </div>

        <!-- Store dialog -->
        <Dialog v-model:open="storeDialog">
            <DialogContent>
                <form @submit.prevent="submitStore">
                    <DialogHeader>
                        <DialogTitle>{{ editingStore ? 'Edit store' : 'New store' }}</DialogTitle>
                        <DialogDescription> New stores start with no stock rows until a product is created. </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-2">
                            <Label for="s-name">Name</Label>
                            <Input id="s-name" v-model="storeForm.name" required autofocus />
                            <InputError :message="storeForm.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="s-address">Address</Label>
                            <Input id="s-address" v-model="storeForm.address" />
                            <InputError :message="storeForm.errors.address" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="s-phone">Phone</Label>
                            <Input id="s-phone" v-model="storeForm.phone" class="font-mono" />
                            <InputError :message="storeForm.errors.phone" />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="storeDialog = false">Cancel</Button>
                        <Button type="submit" class="press" :disabled="storeForm.processing">
                            {{ editingStore ? 'Save' : 'Create' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Register dialog -->
        <Dialog v-model:open="registerDialog">
            <DialogContent>
                <form @submit.prevent="submitRegister">
                    <DialogHeader>
                        <DialogTitle>{{ editingRegister ? 'Edit register' : 'New register' }}</DialogTitle>
                        <DialogDescription> In {{ registerStore?.name }}. Registers label which terminal made a sale. </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-2">
                            <Label for="r-name">Name</Label>
                            <Input id="r-name" v-model="registerForm.name" required autofocus placeholder="Register 2" />
                            <InputError :message="registerForm.errors.name" />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="registerDialog = false">Cancel</Button>
                        <Button type="submit" class="press" :disabled="registerForm.processing">
                            {{ editingRegister ? 'Save' : 'Create' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Delete confirmation -->
        <Dialog :open="!!pendingDelete" @update:open="(v) => !v && (pendingDelete = null)">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete “{{ pendingDelete?.name }}”?</DialogTitle>
                    <DialogDescription>
                        Its registers, stock rows and inventory history go with it. A store that has ever recorded a sale cannot be deleted at all —
                        those orders must keep pointing at a real shop.
                    </DialogDescription>
                </DialogHeader>

                <div class="py-2">
                    <p class="tabular font-mono text-xs text-muted-foreground">
                        {{ pendingDelete?.orders_count ?? 0 }} orders · {{ pendingDelete?.users_count ?? 0 }} staff ·
                        {{ pendingDelete?.registers?.length ?? 0 }} registers
                    </p>
                </div>

                <DialogFooter>
                    <Button variant="ghost" class="press" @click="pendingDelete = null">Cancel</Button>
                    <Button class="press bg-destructive text-destructive-foreground hover:bg-destructive/90" @click="confirmDelete">Delete</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
