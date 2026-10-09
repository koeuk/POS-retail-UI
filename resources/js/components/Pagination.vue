<script setup lang="ts">
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { PaginationLink } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
    /** Rows per page the server actually used. */
    perPage?: number;
}>();

const page = usePage();

/*
 * Straight from App\Support\PerPage via the shared Inertia props — one
 * source of truth, so the two whitelists cannot drift. The literals only
 * cover a stale client bundle talking to a newer server.
 */
const shared = computed(() => (page.props.pagination as { options?: number[]; default?: number } | undefined) ?? {});
const PER_PAGE_OPTIONS = computed(() => shared.value.options ?? [10, 20, 50, 100, 150, 200]);
const perPage = computed(() => props.perPage ?? shared.value.default ?? 20);

/*
 * Changing the page size restarts at page 1: keeping `page=3` while shrinking
 * to 10 rows could land past the end of a short list and show nothing.
 * Every other filter in the query string is kept.
 */
function changePerPage(value: unknown) {
    const size = Number(value);

    /*
     * Refuse anything off the whitelist — junk in the URL would be silently
     * swapped for the default server-side while the URL kept lying — and
     * refuse a re-pick of the current size: reka emits those (a native
     * select never did), and navigating would only reset the reader to
     * page 1 for nothing.
     */
    if (!PER_PAGE_OPTIONS.value.includes(size) || size === perPage.value) return;

    const url = new URL(page.url, window.location.origin);

    url.searchParams.set('per_page', String(size));
    url.searchParams.delete('page');

    router.get(url.pathname + url.search, {}, { preserveState: true, preserveScroll: true, replace: true });
}

/** Laravel emits "&laquo; Previous" / "Next &raquo;" as raw entities. */
const clean = (label: string) => label.replace(/&laquo;|&raquo;/g, '').trim();

/*
 * Laravel always puts Previous first and Next last. A phone gets those two and
 * nothing else — a row of numbered page chips is a mouse affordance, and at
 * 360px it wraps onto three lines that are all too small to hit.
 */
const previous = computed(() => props.links[0]);
const next = computed(() => props.links[props.links.length - 1]);
const numbered = computed(() => props.links.slice(1, -1));
const hasPages = computed(() => numbered.value.length > 1);
</script>

<template>
    <div v-if="total > 0" class="flex items-center justify-between gap-3 border-t border-border px-4 py-3">
        <div class="flex items-center gap-3">
            <p class="font-mono text-xs text-muted-foreground">
                <span class="tabular">{{ from ?? 0 }}–{{ to ?? 0 }}</span>
                of
                <span class="tabular">{{ total }}</span>
            </p>

            <!--
                Always shown on a non-empty list. It used to hide until the
                list outgrew the smallest page size, but a page size is a
                preference set before the list grows — a control that only
                appears once it is needed is one nobody can find.
            -->
            <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <span class="hidden sm:inline">Show</span>
                <Select :model-value="String(perPage)" @update:model-value="changePerPage">
                    <SelectTrigger class="tabular h-8 w-[4.75rem] font-mono text-xs" aria-label="Rows per page">
                        <!-- The placeholder is the current size, so an off-list value can never render a blank trigger. -->
                        <SelectValue :placeholder="String(perPage)" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="n in PER_PAGE_OPTIONS" :key="n" :value="String(n)" class="tabular font-mono text-xs">
                            {{ n }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>

        <!-- Phone: two large targets. -->
        <nav v-if="hasPages" class="flex items-center gap-1.5 md:hidden">
            <component
                :is="previous.url ? Link : 'span'"
                :href="previous.url ?? undefined"
                preserve-scroll
                class="press flex h-10 min-w-10 items-center justify-center rounded-lg border border-border px-3"
                :class="previous.url ? '' : 'pointer-events-none opacity-40'"
                aria-label="Previous page"
            >
                <ChevronLeft class="size-4" />
            </component>
            <component
                :is="next.url ? Link : 'span'"
                :href="next.url ?? undefined"
                preserve-scroll
                class="press flex h-10 min-w-10 items-center justify-center rounded-lg border border-border px-3"
                :class="next.url ? '' : 'pointer-events-none opacity-40'"
                aria-label="Next page"
            >
                <ChevronRight class="size-4" />
            </component>
        </nav>

        <!-- Desktop: the full numbered run. -->
        <nav v-if="hasPages" class="hidden items-center gap-1 md:flex">
            <template v-for="(link, i) in links" :key="i">
                <span v-if="!link.url" class="px-2.5 py-1 text-xs text-muted-foreground/50">
                    {{ clean(link.label) }}
                </span>
                <Link
                    v-else
                    :href="link.url"
                    preserve-scroll
                    class="press rounded-md px-2.5 py-1 font-mono text-xs transition-colors"
                    :class="link.active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'"
                >
                    {{ clean(link.label) }}
                </Link>
            </template>
        </nav>
    </div>
</template>
