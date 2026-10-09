<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, History } from 'lucide-vue-next';

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
    causer: { id: number; name: string; email: string } | null;
    store: { id: number; name: string } | null;
    ip_address: string | null;
    changes: Change[];
    properties: Record<string, string>;
    created_at: string | null;
}

defineProps<{
    entries: Paginated<ActivityRow>;
    subject: { type: string; id: number; label: string; exists: boolean };
    summary: { total: number; first_at: string | null; last_at: string | null };
    /** The section this record lives in — Products, Customers, … */
    parent: { title: string; href: string };
    /** This page's own URL, e.g. /products/21/history. */
    self_href: string;
}>();

/** Badge variant per event, mirroring the movement ledger's colour logic. */
const eventTone = (event: string | null) => (event === 'created' ? 'default' : event === 'deleted' ? 'destructive' : 'secondary');

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' }) : '—');

const day = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '—');

const fieldLabel = (field: string) =>
    field
        .replace(/_id$/, '')
        .replace(/_/g, ' ')
        .replace(/^./, (c) => c.toUpperCase());
</script>

<template>
    <Head :title="`History — ${subject.label}`" />

    <AppLayout
        :breadcrumbs="[
            { title: parent.title, href: parent.href },
            { title: subject.label, href: self_href },
        ]"
    >
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader :title="subject.label" :description="`Everything recorded for this ${subject.type.toLowerCase()}.`">
                <template #actions>
                    <Button as-child variant="ghost" class="press">
                        <Link :href="parent.href">
                            <ArrowLeft class="size-4" />
                            Back
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <div class="grid items-start gap-4 lg:grid-cols-3">
                <!-- Identity -->
                <section class="animate-rise rounded-xl border border-border bg-card p-5">
                    <div class="mb-4 flex items-center justify-center rounded-lg border border-border bg-muted/40 py-10">
                        <History class="size-8 text-muted-foreground/50" />
                    </div>

                    <div class="flex items-center gap-2">
                        <Badge variant="secondary">{{ subject.type }}</Badge>
                        <Badge v-if="!subject.exists" variant="destructive">Deleted</Badge>
                    </div>

                    <p v-if="!subject.exists" class="mt-3 text-sm text-muted-foreground">This record has since been deleted — its history remains.</p>

                    <dl class="mt-4 space-y-2.5 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Record</dt>
                            <dd class="tabular font-mono">#{{ subject.id }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Entries</dt>
                            <dd class="tabular font-mono">{{ summary.total }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">First recorded</dt>
                            <dd>{{ day(summary.first_at) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Last change</dt>
                            <dd>{{ day(summary.last_at) }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- Ledger. min-w-0: a grid item defaults to min-width:auto and
                     will not shrink below its widest row. -->
                <section class="animate-rise min-w-0 rounded-xl border border-border bg-card lg:col-span-2" style="animation-delay: 60ms">
                    <h2 class="border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Change history
                    </h2>

                    <!-- Desktop: the same table shape as every list screen. -->
                    <div v-if="entries.data.length" class="hidden overflow-x-auto md:block">
                        <Table>
                            <TableHeader>
                                <TableRow class="hover:bg-transparent">
                                    <TableHead class="w-[1%] whitespace-nowrap">When</TableHead>
                                    <TableHead class="w-[1%] whitespace-nowrap">Action</TableHead>
                                    <TableHead>What changed</TableHead>
                                    <TableHead>By</TableHead>
                                </TableRow>
                            </TableHeader>
                            <tbody class="[&_tr:last-child]:border-0">
                                <TableRow v-for="a in entries.data" :key="a.id" class="align-top">
                                    <TableCell class="tabular whitespace-nowrap font-mono text-xs text-muted-foreground">
                                        {{ when(a.created_at) }}
                                    </TableCell>
                                    <TableCell>
                                        <Badge :variant="eventTone(a.event)" class="capitalize">{{ a.event ?? 'note' }}</Badge>
                                    </TableCell>
                                    <TableCell>
                                        <p class="text-sm font-medium leading-tight">{{ a.description }}</p>
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
                                        <span v-if="a.ip_address" class="tabular block font-mono text-xs text-muted-foreground">{{
                                            a.ip_address
                                        }}</span>
                                    </TableCell>
                                </TableRow>
                            </tbody>
                        </Table>
                    </div>

                    <!-- Phone: one card per entry. -->
                    <ul v-if="entries.data.length" class="divide-y divide-border md:hidden">
                        <li v-for="a in entries.data" :key="a.id" class="px-4 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <p class="min-w-0 flex-1 text-sm font-medium leading-tight">{{ a.description }}</p>
                                <Badge :variant="eventTone(a.event)" class="shrink-0 text-[10px] capitalize">{{ a.event ?? 'note' }}</Badge>
                            </div>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                <span class="tabular font-mono">{{ when(a.created_at) }}</span>
                                · {{ a.causer?.name ?? 'System' }}
                                <template v-if="a.store"> · {{ a.store.name }}</template>
                            </p>
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
                            <p v-if="Object.keys(a.properties).length" class="mt-1.5 text-xs text-muted-foreground">
                                <span v-for="(value, key) in a.properties" :key="key" class="mr-2 inline-block">
                                    {{ fieldLabel(String(key)) }}: <span class="tabular font-mono">{{ value }}</span>
                                </span>
                            </p>
                        </li>
                    </ul>

                    <EmptyState
                        v-else
                        :icon="History"
                        :title="`Nothing recorded for ${subject.label} yet`"
                        description="This record has not been changed since the activity log was switched on."
                    />

                    <Pagination :links="entries.links" :from="entries.from" :to="entries.to" :total="entries.total" :per-page="entries.per_page" />
                </section>
            </div>
        </div>
    </AppLayout>
</template>
