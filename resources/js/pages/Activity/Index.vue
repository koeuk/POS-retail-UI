<script setup lang="ts">
import DateRangePicker from '@/components/DateRangePicker.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { currentPerPage } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { History, Search, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Change {
    field: string;
    from: string | null;
    to: string | null;
}

interface ActivityRow {
    id: number;
    log_name: string | null;
    description: string;
    event: string | null;
    subject_type: string | null;
    subject_id: number | null;
    causer: { id: number; name: string; email: string } | null;
    store: { id: number; name: string } | null;
    ip_address: string | null;
    changes: Change[];
    properties: Record<string, string>;
    created_at: string | null;
}

const props = defineProps<{
    activities: Paginated<ActivityRow>;
    filters: {
        search?: string;
        log_name?: string;
        event?: string;
        causer_id?: string;
        store_id?: string;
        subject_type?: string;
        subject_id?: string;
        from?: string;
        to?: string;
    };
    options: {
        logNames: Record<string, string>;
        events: string[];
        staff: { id: number; name: string }[];
        stores: { id: number; name: string }[];
    };
}>();

const ALL = 'all';
const search = ref(props.filters.search ?? '');
const logName = ref(props.filters.log_name || ALL);
const event = ref(props.filters.event || ALL);
const causerId = ref(props.filters.causer_id || ALL);
const storeId = ref(props.filters.store_id || ALL);
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

/*
 * Set when the History button on a Product (or any other record) sent us
 * here. It is not one of the dropdowns — it scopes the whole screen to one
 * record — so it rides along on every reload and is cleared by its own chip.
 */
const subjectType = ref(props.filters.subject_type ?? '');
const subjectId = ref(props.filters.subject_id ?? '');

const subjectScope = computed(() => (subjectType.value && subjectId.value ? `${subjectType.value} #${subjectId.value}` : null));

let debounce: ReturnType<typeof setTimeout>;

const clean = (value: string) => (value === ALL || value === '' ? undefined : value);

function reload() {
    router.get(
        route('activity.index'),
        {
            filter: {
                search: search.value || undefined,
                log_name: clean(logName.value),
                event: clean(event.value),
                causer_id: clean(causerId.value),
                store_id: clean(storeId.value),
                subject_type: clean(subjectType.value),
                subject_id: clean(subjectId.value),
                from: clean(from.value),
                to: clean(to.value),
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

watch([logName, event, causerId, storeId, subjectType, subjectId, from, to], reload);

const hasFilters = computed(
    () =>
        !!search.value ||
        [logName.value, event.value, causerId.value, storeId.value].some((v) => v !== ALL) ||
        !!subjectScope.value ||
        !!from.value ||
        !!to.value,
);

function clearFilters() {
    search.value = '';
    logName.value = ALL;
    event.value = ALL;
    causerId.value = ALL;
    storeId.value = ALL;
    subjectType.value = '';
    subjectId.value = '';
    from.value = '';
    to.value = '';
}

/** Colour by what kind of thing happened, so the eye can skim one class. */
const toneFor: Record<string, string> = {
    money: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    access: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    auth: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    model: 'bg-muted text-muted-foreground',
};

const logLabel = (name: string | null) => (name ? (props.options.logNames[name] ?? name) : '—');

const dateFormat = new Intl.DateTimeFormat(undefined, {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

const when = (iso: string | null) => (iso ? dateFormat.format(new Date(iso)) : '—');

/** Field names read better as words than as columns: units_per_pack → Units per pack. */
const fieldLabel = (field: string) =>
    field
        .replace(/_id$/, '')
        .replace(/_/g, ' ')
        .replace(/^./, (c) => c.toUpperCase());

const subjectLabel = (row: ActivityRow) => (row.subject_type ? `${row.subject_type} #${row.subject_id}` : null);
</script>

<template>
    <Head title="Activity Log" />

    <AppLayout :breadcrumbs="[{ title: 'Activity Log', href: '/activity' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader title="Activity Log" description="Who did what, and when. Read-only — entries are never edited, only aged out." />

            <div class="list-panel animate-rise" style="animation-delay: 60ms">
                <div class="border-b border-border p-3">
                    <!-- Arrived from a record's History button: say so plainly,
                         because otherwise a near-empty log looks like a bug. -->
                    <div v-if="subjectScope" class="mb-2 flex items-center gap-2">
                        <Badge variant="secondary" class="gap-1.5 rounded-full py-1 pl-3 pr-1.5">
                            <span>History for {{ subjectScope }}</span>
                            <button
                                type="button"
                                class="press rounded-full p-0.5 hover:bg-background/60"
                                aria-label="Show the whole log"
                                @click="
                                    subjectType = '';
                                    subjectId = '';
                                "
                            >
                                <X class="size-3.5" />
                            </button>
                        </Badge>
                    </div>

                    <!-- One row: search flexes, the filters keep their width and
                         wrap under it on screens too narrow to hold them all. -->
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative min-w-[14rem] flex-1">
                            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                v-model="search"
                                placeholder="Search description, staff or IP…"
                                class="h-10 rounded-full pl-9"
                                autocomplete="off"
                            />
                        </div>

                        <Select v-model="logName">
                            <SelectTrigger class="h-10 w-auto min-w-[9rem] shrink-0 rounded-full" aria-label="Kind"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL">Everything</SelectItem>
                                <SelectItem v-for="(label, key) in options.logNames" :key="key" :value="String(key)">{{ label }}</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select v-model="causerId">
                            <SelectTrigger class="h-10 w-auto min-w-[8rem] shrink-0 rounded-full" aria-label="Staff"
                                ><SelectValue placeholder="Anyone"
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL">Anyone</SelectItem>
                                <SelectItem v-for="s in options.staff" :key="s.id" :value="String(s.id)">{{ s.name }}</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select v-if="options.events.length" v-model="event">
                            <SelectTrigger class="h-10 w-auto min-w-[8rem] shrink-0 rounded-full" aria-label="Action"
                                ><SelectValue placeholder="Any action"
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL">Any action</SelectItem>
                                <SelectItem v-for="e in options.events" :key="e" :value="e">{{ fieldLabel(e) }}</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select v-if="options.stores.length > 1" v-model="storeId">
                            <SelectTrigger class="h-10 w-auto min-w-[8rem] shrink-0 rounded-full" aria-label="Store"
                                ><SelectValue placeholder="All stores"
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL">All stores</SelectItem>
                                <SelectItem v-for="s in options.stores" :key="s.id" :value="String(s.id)">{{ s.name }}</SelectItem>
                            </SelectContent>
                        </Select>

                        <DateRangePicker
                            :from="from || undefined"
                            :to="to || undefined"
                            placeholder="Any date"
                            class="h-10 shrink-0 rounded-full"
                            @update:from="(v) => (from = v ?? '')"
                            @update:to="(v) => (to = v ?? '')"
                        />

                        <Button v-if="hasFilters" variant="ghost" class="press h-10 shrink-0 rounded-full" @click="clearFilters">
                            <X class="size-4" />
                            Clear
                        </Button>
                    </div>
                </div>

                <!-- Desktop: one row per entry, changes inline underneath. -->
                <div v-if="activities.data.length" class="hidden overflow-x-auto md:block">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead class="w-[1%] whitespace-nowrap">When</TableHead>
                                <TableHead>What happened</TableHead>
                                <TableHead>Who</TableHead>
                                <TableHead class="w-[1%] whitespace-nowrap">Kind</TableHead>
                            </TableRow>
                        </TableHeader>
                        <tbody class="[&_tr:last-child]:border-0">
                            <TableRow v-for="a in activities.data" :key="a.id" class="group align-top">
                                <TableCell class="tabular whitespace-nowrap font-mono text-xs text-muted-foreground">
                                    {{ when(a.created_at) }}
                                </TableCell>

                                <TableCell>
                                    <p class="font-medium leading-tight">{{ a.description }}</p>

                                    <p v-if="subjectLabel(a)" class="tabular font-mono text-xs text-muted-foreground">
                                        {{ subjectLabel(a) }}
                                    </p>

                                    <!-- The diff. Only fields that actually changed reach here. -->
                                    <ul v-if="a.changes.length" class="mt-1.5 space-y-0.5">
                                        <li v-for="c in a.changes" :key="c.field" class="text-xs leading-snug">
                                            <span class="text-muted-foreground">{{ fieldLabel(c.field) }}:</span>
                                            <span v-if="c.from" class="tabular font-mono text-muted-foreground line-through">{{ c.from }}</span>
                                            <span v-else class="italic text-muted-foreground">empty</span>
                                            <span class="text-muted-foreground"> → </span>
                                            <span v-if="c.to" class="tabular font-mono">{{ c.to }}</span>
                                            <span v-else class="italic text-muted-foreground">empty</span>
                                        </li>
                                    </ul>

                                    <!-- Money and auth entries carry a payload instead of a diff. -->
                                    <p v-if="Object.keys(a.properties).length" class="mt-1.5 text-xs text-muted-foreground">
                                        <span v-for="(value, key) in a.properties" :key="key" class="mr-2 inline-block">
                                            {{ fieldLabel(String(key)) }}: <span class="tabular font-mono">{{ value }}</span>
                                        </span>
                                    </p>
                                </TableCell>

                                <TableCell class="text-sm">
                                    <span v-if="a.causer" class="font-medium">{{ a.causer.name }}</span>
                                    <span v-else class="italic text-muted-foreground">System</span>
                                    <span v-if="a.store" class="block text-xs text-muted-foreground">{{ a.store.name }}</span>
                                    <span v-if="a.ip_address" class="tabular block font-mono text-xs text-muted-foreground">
                                        {{ a.ip_address }}
                                    </span>
                                </TableCell>

                                <TableCell>
                                    <Badge variant="secondary" class="whitespace-nowrap" :class="toneFor[a.log_name ?? 'model']">
                                        {{ logLabel(a.log_name) }}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        </tbody>
                    </Table>
                </div>

                <!-- Phone: one card per entry. -->
                <ul v-if="activities.data.length" class="space-y-2 p-2.5 md:hidden">
                    <li v-for="a in activities.data" :key="a.id" class="rounded-xl border border-border bg-card px-3.5 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <p class="min-w-0 flex-1 font-medium leading-tight">{{ a.description }}</p>
                            <Badge variant="secondary" class="shrink-0 whitespace-nowrap text-[10px]" :class="toneFor[a.log_name ?? 'model']">
                                {{ logLabel(a.log_name) }}
                            </Badge>
                        </div>

                        <p class="mt-0.5 text-xs text-muted-foreground">
                            <span class="tabular font-mono">{{ when(a.created_at) }}</span>
                            ·
                            <span>{{ a.causer?.name ?? 'System' }}</span>
                            <template v-if="a.store"> · {{ a.store.name }}</template>
                        </p>

                        <ul v-if="a.changes.length" class="mt-2 space-y-0.5">
                            <li v-for="c in a.changes" :key="c.field" class="text-xs leading-snug">
                                <span class="text-muted-foreground">{{ fieldLabel(c.field) }}:</span>
                                <span v-if="c.from" class="tabular font-mono text-muted-foreground line-through">{{ c.from }}</span>
                                <span v-else class="italic text-muted-foreground">empty</span>
                                <span class="text-muted-foreground"> → </span>
                                <span v-if="c.to" class="tabular font-mono">{{ c.to }}</span>
                                <span v-else class="italic text-muted-foreground">empty</span>
                            </li>
                        </ul>

                        <p v-if="Object.keys(a.properties).length" class="mt-2 text-xs text-muted-foreground">
                            <span v-for="(value, key) in a.properties" :key="key" class="mr-2 inline-block">
                                {{ fieldLabel(String(key)) }}: <span class="tabular font-mono">{{ value }}</span>
                            </span>
                        </p>
                    </li>
                </ul>

                <EmptyState
                    v-else
                    :icon="History"
                    :title="
                        subjectScope
                            ? `Nothing recorded for ${subjectScope} yet`
                            : hasFilters
                              ? 'Nothing matches those filters'
                              : 'Nothing recorded yet'
                    "
                    :description="
                        subjectScope
                            ? 'This record has not been changed since the activity log was switched on.'
                            : hasFilters
                              ? 'Try a wider date range, or clear the filters.'
                              : 'Sales, sign-ins and record changes will appear here as staff use the shop.'
                    "
                >
                    <Button v-if="hasFilters" variant="outline" class="press" @click="clearFilters">Clear filters</Button>
                </EmptyState>

                <Pagination
                    :links="activities.links"
                    :from="activities.from"
                    :to="activities.to"
                    :total="activities.total"
                    :per-page="activities.per_page"
                />
            </div>
        </div>
    </AppLayout>
</template>
