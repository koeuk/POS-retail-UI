<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import HistoryButton from '@/components/HistoryButton.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { currentPerPage } from '@/lib/utils';
import type { Paginated, SharedData, Store, User, Vendor } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Search, ShieldCheck, Trash2, Users } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type PermissionOption = {
    value: string;
    label: string;
    group: string;
    defaults: Record<string, boolean>;
    /** Per role, per action — a role may reach an area yet not every verb in it. */
    actionDefaults: Record<string, Record<string, boolean>>;
};

type ActionOption = { value: string; label: string };

/** One area's switches: {view: true, delete: false}. */
type ActionMap = Record<string, boolean>;

/** What the server sends per user: the area answer plus its actions. */
type EffectivePermission = { allowed: boolean; actions: ActionMap };

const props = defineProps<{
    users: Paginated<
        User & {
            store?: Pick<Store, 'id' | 'name'> | null;
            vendor?: Pick<Vendor, 'id' | 'name'> | null;
            effective_permissions: Record<string, EffectivePermission>;
        }
    >;
    stores: Store[];
    vendors: Pick<Vendor, 'id' | 'name'>[];
    roles: { value: string; label: string }[];
    permissionOptions: PermissionOption[];
    actionOptions: ActionOption[];
    filters: { search?: string; role?: string };
}>();

const page = usePage<SharedData>();
const currentUserId = computed(() => page.props.auth.user?.id);
const canEditPermissions = computed(() => page.props.auth.can.isAdmin);
/** A vendor's hires join its own team, so it is never asked which vendor. */
const actorIsVendor = computed(() => page.props.auth.user?.role === 'vendor');

/** Options grouped for display: [group label, options[]]. */
const permissionGroups = computed(() => {
    const groups = new Map<string, PermissionOption[]>();
    for (const option of props.permissionOptions) {
        groups.set(option.group, [...(groups.get(option.group) ?? []), option]);
    }
    return [...groups.entries()];
});

/** The role's baseline as a full matrix, action by action. */
const roleDefaults = (role: string): Record<string, ActionMap> =>
    Object.fromEntries(
        props.permissionOptions.map((o) => [
            o.value,
            Object.fromEntries(props.actionOptions.map((a) => [a.value, o.actionDefaults?.[role]?.[a.value] ?? o.defaults[role] ?? false])),
        ]),
    );

/** Server shape → form shape: keep the actions, drop the area summary. */
const toActionMatrix = (effective: Record<string, EffectivePermission>): Record<string, ActionMap> =>
    Object.fromEntries(Object.entries(effective).map(([key, value]) => [key, { ...value.actions }]));

const ALL = 'all';
const NONE = 'none';

const search = ref(props.filters.search ?? '');
const roleFilter = ref(props.filters.role ?? ALL);
let debounce: ReturnType<typeof setTimeout>;

function reload() {
    router.get(
        route('users.index'),
        {
            filter: {
                search: search.value || undefined,
                role: roleFilter.value === ALL ? undefined : roleFilter.value,
            },
            per_page: currentPerPage(),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(reload, 300);
});
watch(roleFilter, reload);

const editing = ref<User | null>(null);
const dialogOpen = ref(false);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'cashier',
    store_id: NONE,
    vendor_id: NONE,
    is_active: true as boolean,
    permissions: {} as Record<string, ActionMap>,
});

/** A cashier cannot open /pos without a store, so the field becomes required. */
const storeRequired = computed(() => form.role === 'cashier');

/** A vendor account must name its vendor; a cashier may join a vendor's team. */
const vendorRequired = computed(() => form.role === 'vendor');
const showVendor = computed(() => !actorIsVendor.value && (form.role === 'vendor' || form.role === 'cashier'));

/*
 * Picking a role re-seeds the switches to that role's defaults — the role is
 * the baseline, the switches are the deviations from it. Wired to the Select
 * itself rather than a watcher so opening an existing account (which also
 * sets form.role) cannot wipe the loaded permissions.
 */
function reseedFromRole(role: unknown) {
    form.permissions = roleDefaults(String(role));
}

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.store_id = NONE;
    form.vendor_id = NONE;
    form.permissions = roleDefaults(form.role);
    dialogOpen.value = true;
}

function openEdit(user: User & { effective_permissions: Record<string, EffectivePermission> }) {
    editing.value = user;
    form.clearErrors();
    form.name = user.name;
    form.email = user.email;
    form.password = '';
    form.password_confirmation = '';
    form.role = user.role;
    form.store_id = user.store_id ? String(user.store_id) : NONE;
    form.vendor_id = user.vendor_id ? String(user.vendor_id) : NONE;
    form.is_active = user.is_active;
    form.permissions = toActionMatrix(user.effective_permissions);
    dialogOpen.value = true;
}

function submit() {
    const opts = { onSuccess: () => (dialogOpen.value = false), preserveScroll: true };

    form.transform((d) => ({
        ...d,
        store_id: d.store_id === NONE ? null : d.store_id,
        vendor_id: d.vendor_id === NONE ? null : d.vendor_id,
    }));

    if (editing.value) {
        form.put(route('users.update', { user: editing.value.uuid }), opts);
    } else {
        form.post(route('users.store'), opts);
    }
}

/*
 * Permissions get their own dialog and their own form. Sharing the edit
 * form would mean a permissions save also PUTs name, email and role — so a
 * stale field on screen could quietly overwrite a colleague's edit. This
 * sends only the switches.
 */
const permissionsFor = ref<(User & { effective_permissions: Record<string, EffectivePermission> }) | null>(null);
const permissionsOpen = ref(false);
const permissionsForm = useForm({ permissions: {} as Record<string, ActionMap> });

function openPermissions(user: User & { effective_permissions: Record<string, EffectivePermission> }) {
    permissionsFor.value = user;
    permissionsForm.clearErrors();
    permissionsForm.permissions = toActionMatrix(user.effective_permissions);
    permissionsOpen.value = true;
}

/** Whole-row toggle: the area's checkbox drives all four of its actions. */
function toggleArea(area: string, granted: boolean) {
    permissionsForm.permissions[area] = Object.fromEntries(props.actionOptions.map((a) => [a.value, granted]));
}

const areaGranted = (area: string) => props.actionOptions.some((a) => permissionsForm.permissions[area]?.[a.value]);

/** Back to what the role alone grants — clears every per-user override. */
function resetToRoleDefaults() {
    if (!permissionsFor.value) return;
    permissionsForm.permissions = roleDefaults(permissionsFor.value.role);
}

function submitPermissions() {
    if (!permissionsFor.value) return;

    permissionsForm.put(route('users.permissions', { user: permissionsFor.value.uuid }), {
        preserveScroll: true,
        onSuccess: () => (permissionsOpen.value = false),
    });
}

const pendingDelete = ref<User | null>(null);

function confirmDelete() {
    if (!pendingDelete.value) return;
    router.delete(route('users.destroy', { user: pendingDelete.value.uuid }), {
        preserveScroll: true,
        onFinish: () => (pendingDelete.value = null),
    });
}

const roleTone = (role: string) => (role === 'admin' ? 'default' : role === 'manager' || role === 'vendor' ? 'secondary' : 'outline');
</script>

<template>
    <Head title="Staff" />

    <AppLayout :breadcrumbs="[{ title: 'Staff', href: '/users' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader title="Staff" description="Accounts are created here — there is no public sign-up. Everyone signs in on the same login page.">
                <template #actions>
                    <Button class="press" @click="openCreate">
                        <Plus class="size-4" />
                        New staff
                    </Button>
                </template>
            </PageHeader>

            <div class="list-panel animate-rise" style="animation-delay: 60ms">
                <!-- Same shape as Order History: full-width search, chips below. -->
                <div class="flex items-center gap-2 border-b border-border p-3">
                    <div class="relative min-w-0 flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Search name or email…" class="h-10 rounded-full pl-9" autocomplete="off" />
                    </div>
                    <Select v-model="roleFilter">
                        <SelectTrigger class="h-10 w-auto min-w-[7rem] shrink-0 rounded-full">
                            <SelectValue placeholder="Role" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All roles</SelectItem>
                            <SelectItem v-for="r in roles" :key="r.value" :value="r.value">
                                {{ r.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="users.data.length" class="hidden overflow-x-auto md:block">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Name</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Store</TableHead>
                                <TableHead>Status</TableHead>
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
                            <TableRow v-for="u in users.data" :key="u.id" class="group">
                                <TableCell>
                                    <p class="font-medium leading-tight">
                                        {{ u.name }}
                                        <span v-if="u.id === currentUserId" class="ml-1 text-xs text-muted-foreground">(you)</span>
                                    </p>
                                    <p class="text-xs text-muted-foreground">{{ u.email }}</p>
                                </TableCell>
                                <TableCell>
                                    <Badge :variant="roleTone(u.role)" class="capitalize">{{ u.role }}</Badge>
                                </TableCell>
                                <TableCell class="text-sm text-muted-foreground">
                                    {{ u.store?.name ?? 'All stores' }}
                                    <span v-if="u.vendor" class="block text-xs">{{ u.vendor.name }}</span>
                                </TableCell>
                                <TableCell>
                                    <span class="inline-flex items-center gap-1.5 text-sm" :class="u.is_active ? '' : 'text-muted-foreground'">
                                        <span class="size-1.5 rounded-full" :class="u.is_active ? 'bg-primary' : 'bg-muted-foreground/40'" />
                                        {{ u.is_active ? 'Active' : 'Disabled' }}
                                    </span>
                                </TableCell>
                                <TableCell>
                                    <div class="flex items-center gap-1">
                                        <!-- Admins hold every permission by definition, so the
                                             button is disabled rather than hidden: a control that
                                             vanishes makes people hunt for it, a dead one with a
                                             reason attached answers the question on the spot. -->
                                        <Button
                                            v-if="canEditPermissions"
                                            variant="ghost"
                                            size="icon"
                                            class="press size-8 disabled:opacity-40"
                                            :disabled="u.role === 'admin'"
                                            :aria-label="`Permissions for ${u.name}`"
                                            :title="u.role === 'admin' ? 'Administrators always have every permission' : `Permissions for ${u.name}`"
                                            @click="openPermissions(u)"
                                        >
                                            <ShieldCheck class="size-4" />
                                        </Button>
                                        <HistoryButton subject-type="User" :subject-id="u.uuid" :label="u.name" />
                                        <Button variant="ghost" size="icon" class="press size-8" aria-label="Edit" @click="openEdit(u)">
                                            <Pencil class="size-4" />
                                        </Button>
                                        <Button
                                            v-if="u.id !== currentUserId"
                                            variant="ghost"
                                            size="icon"
                                            class="press size-8 text-muted-foreground hover:text-destructive"
                                            aria-label="Delete"
                                            @click="pendingDelete = u"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TransitionGroup>
                    </Table>
                </div>

                <ul v-if="users.data.length" class="md:hidden">
                    <li v-for="u in users.data" :key="u.id" class="list-row">
                        <button type="button" class="list-row-main" :aria-label="`Edit ${u.name}`" @click="openEdit(u)">
                            <span
                                class="mt-1 size-1.5 shrink-0 self-start rounded-full"
                                :class="u.is_active ? 'bg-primary' : 'bg-muted-foreground/40'"
                            />

                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium leading-tight">
                                    {{ u.name }}
                                    <span v-if="u.id === currentUserId" class="text-xs font-normal text-muted-foreground">(you)</span>
                                </p>
                                <p class="truncate text-xs text-muted-foreground">
                                    {{ u.email }} · {{ u.store?.name ?? 'All stores' }}<template v-if="u.vendor"> · {{ u.vendor.name }}</template>
                                </p>
                            </div>

                            <Badge :variant="roleTone(u.role)" class="shrink-0 capitalize">{{ u.role }}</Badge>
                        </button>

                        <button
                            v-if="canEditPermissions && u.role !== 'admin'"
                            type="button"
                            class="list-row-action"
                            :aria-label="`Permissions for ${u.name}`"
                            @click="openPermissions(u)"
                        >
                            <ShieldCheck class="size-4" />
                        </button>

                        <button
                            v-if="u.id !== currentUserId"
                            type="button"
                            class="list-row-action"
                            :aria-label="`Delete ${u.name}`"
                            @click="pendingDelete = u"
                        >
                            <Trash2 class="size-4" />
                        </button>
                        <!-- Keeps the name column aligned across rows where the
                             delete control is absent (you cannot delete yourself). -->
                        <span v-else class="w-12 shrink-0" aria-hidden="true" />
                    </li>
                </ul>

                <EmptyState v-else :icon="Users" title="No staff found" description="Try clearing the filters." />

                <Pagination :links="users.links" :from="users.from" :to="users.to" :total="users.total" :per-page="users.per_page" />
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <form @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{ editing ? 'Edit staff account' : 'New staff account' }}</DialogTitle>
                        <DialogDescription>
                            <span v-if="editing">Leave the password blank to keep the current one.</span>
                            <span v-else>They sign in at the same login page as everyone else.</span>
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="u-name">Name</Label>
                                <Input id="u-name" v-model="form.name" required autofocus />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="u-email">Email</Label>
                                <Input id="u-email" v-model="form.email" type="email" required />
                                <InputError :message="form.errors.email" />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="u-role">Role</Label>
                                <Select v-model="form.role" :disabled="editing?.id === currentUserId" @update:model-value="reseedFromRole">
                                    <SelectTrigger id="u-role">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="r in roles" :key="r.value" :value="r.value">
                                            {{ r.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.role" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="u-store">
                                    Store
                                    <span v-if="storeRequired" class="text-destructive">*</span>
                                </Label>
                                <Select v-model="form.store_id">
                                    <SelectTrigger id="u-store">
                                        <SelectValue placeholder="All stores" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="NONE" :disabled="storeRequired">All stores</SelectItem>
                                        <SelectItem v-for="s in stores" :key="s.id" :value="String(s.id)">
                                            {{ s.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p v-if="storeRequired" class="text-xs text-muted-foreground">
                                    A cashier must have a store — the POS reads stock from it.
                                </p>
                                <InputError :message="form.errors.store_id" />
                            </div>
                        </div>

                        <div v-if="showVendor" class="grid gap-2">
                            <Label for="u-vendor">
                                Vendor
                                <span v-if="vendorRequired" class="text-destructive">*</span>
                            </Label>
                            <Select v-model="form.vendor_id">
                                <SelectTrigger id="u-vendor">
                                    <SelectValue placeholder="No vendor" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="NONE" :disabled="vendorRequired">No vendor</SelectItem>
                                    <SelectItem v-for="v in vendors" :key="v.id" :value="String(v.id)">
                                        {{ v.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p class="text-xs text-muted-foreground">
                                {{ vendorRequired ? 'The supplier this account speaks for.' : "Optional — puts this cashier on a vendor's team." }}
                            </p>
                            <InputError :message="form.errors.vendor_id" />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="u-pass">Password</Label>
                                <Input id="u-pass" v-model="form.password" type="password" :required="!editing" autocomplete="new-password" />
                                <InputError :message="form.errors.password" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="u-pass2">Confirm password</Label>
                                <Input id="u-pass2" v-model="form.password_confirmation" type="password" autocomplete="new-password" />
                            </div>
                        </div>

                        <!--
                            What this account may open. Role picks the baseline;
                            each switch is a per-user deviation from it. Hidden
                            for admin accounts (they always hold everything) and
                            from non-admin editors (only admins hand out access).
                        -->
                        <div v-if="canEditPermissions && form.role !== 'admin'" class="rounded-lg border border-border">
                            <div class="border-b border-border px-3 py-2.5">
                                <p class="text-sm font-medium">Permissions</p>
                                <p class="text-xs text-muted-foreground">Pre-set by the role — switch anything on or off for this person alone.</p>
                            </div>
                            <!-- Area access only. Which actions they may take
                                 inside it is the shield button's grid, so this
                                 dialog stays about the account itself. -->
                            <div class="grid gap-4 p-3 sm:grid-cols-2">
                                <div v-for="[group, options] in permissionGroups" :key="group" class="space-y-2">
                                    <p class="font-mono text-[0.65rem] uppercase tracking-[0.18em] text-muted-foreground">{{ group }}</p>
                                    <div v-for="option in options" :key="option.value" class="flex items-center justify-between gap-3">
                                        <span class="text-sm">{{ option.label }}</span>
                                        <Switch
                                            :model-value="actionOptions.some((a) => form.permissions[option.value]?.[a.value])"
                                            :aria-label="option.label"
                                            @update:model-value="
                                                (v) =>
                                                    (form.permissions[option.value] = Object.fromEntries(
                                                        actionOptions.map((a) => [a.value, v === true]),
                                                    ))
                                            "
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p v-else-if="canEditPermissions" class="rounded-lg border border-dashed border-border p-3 text-xs text-muted-foreground">
                            Administrators always have access to everything, so there is nothing to switch off here.
                        </p>

                        <div class="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
                            <div>
                                <p class="text-sm font-medium">Active</p>
                                <p class="text-xs text-muted-foreground">Disabling locks them out on their very next request</p>
                            </div>
                            <Switch v-model="form.is_active" :disabled="editing?.id === currentUserId" />
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

        <!-- Permissions on their own. Same switches as the edit dialog, but
             this saves the switches alone — see permissionsForm. -->
        <Dialog v-model:open="permissionsOpen">
            <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
                <form @submit.prevent="submitPermissions">
                    <DialogHeader>
                        <DialogTitle>Permissions — {{ permissionsFor?.name }}</DialogTitle>
                        <DialogDescription>
                            The <span class="capitalize">{{ permissionsFor?.role }}</span> role sets the baseline. Tick what this person may do in
                            each area — click an area's name to turn its whole row on or off.
                        </DialogDescription>
                    </DialogHeader>

                    <!-- Area down the side, action across the top. The area
                         name is itself a toggle for the whole row, because
                         "all of Products" is the common case and clicking
                         four boxes for it would be the tax on the norm. -->
                    <div class="space-y-5 py-5">
                        <div v-for="[group, options] in permissionGroups" :key="group" class="space-y-1">
                            <p class="font-mono text-[0.65rem] uppercase tracking-[0.18em] text-muted-foreground">{{ group }}</p>

                            <div class="overflow-hidden rounded-lg border border-border">
                                <div class="flex items-center gap-2 border-b border-border bg-muted/40 px-3 py-2">
                                    <span class="min-w-0 flex-1 text-[0.7rem] font-medium text-muted-foreground">Area</span>
                                    <span
                                        v-for="action in actionOptions"
                                        :key="action.value"
                                        class="w-12 shrink-0 text-center text-[0.7rem] font-medium text-muted-foreground"
                                    >
                                        {{ action.label }}
                                    </span>
                                </div>

                                <div
                                    v-for="option in options"
                                    :key="option.value"
                                    class="flex items-center gap-2 border-b border-border px-3 py-2 last:border-b-0"
                                >
                                    <button
                                        type="button"
                                        class="press min-w-0 flex-1 truncate text-left text-sm hover:text-primary"
                                        :title="`Turn every action ${areaGranted(option.value) ? 'off' : 'on'} for ${option.label}`"
                                        @click="toggleArea(option.value, !areaGranted(option.value))"
                                    >
                                        {{ option.label }}
                                    </button>

                                    <label v-for="action in actionOptions" :key="action.value" class="flex w-12 shrink-0 items-center justify-center">
                                        <span class="sr-only">{{ action.label }} {{ option.label }}</span>
                                        <Checkbox
                                            :model-value="permissionsForm.permissions[option.value]?.[action.value] ?? false"
                                            @update:model-value="
                                                (v: boolean | 'indeterminate') =>
                                                    (permissionsForm.permissions[option.value][action.value] = v === true)
                                            "
                                        />
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <DialogFooter class="gap-2 sm:justify-between">
                        <Button type="button" variant="ghost" class="press" @click="resetToRoleDefaults">Reset to role defaults</Button>
                        <div class="flex gap-2">
                            <Button type="button" variant="ghost" class="press" @click="permissionsOpen = false">Cancel</Button>
                            <Button type="submit" class="press" :disabled="permissionsForm.processing">Save permissions</Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="!!pendingDelete" @update:open="(v) => !v && (pendingDelete = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {{ pendingDelete?.name }}?</DialogTitle>
                    <DialogDescription>
                        If they have rung up any sales the account is deactivated instead, so the order history keeps its cashier.
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
