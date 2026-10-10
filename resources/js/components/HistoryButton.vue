<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { History } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * The per-row "History" button every list screen carries — one component so
 * they cannot drift. It opens the record's own history page (not the whole
 * Activity Log), and renders nothing for staff without the `activity`
 * permission: the route middleware is still the wall, this just hides a
 * locked door.
 */
const props = defineProps<{
    /** Short model class name, e.g. 'Product' — matches subject_type. */
    subjectType: string;
    /** The record's uuid — what the history route binds on. */
    subjectId: string;
    /** What the record is called, for the accessible label. */
    label: string;
    /** Render as a labelled bar button (phone action bars) instead of a bare icon. */
    withLabel?: boolean;
}>();

const page = usePage<SharedData>();

const visible = computed(() => !!page.props.auth.can.activity);

/** Each record type's own history endpoint — /products/21/history etc. */
const routeNames: Record<string, string> = {
    Product: 'products.history',
    Category: 'categories.history',
    Customer: 'customers.history',
    Store: 'stores.history',
    Stock: 'inventory.history',
    User: 'users.history',
};

const href = computed(() => route(routeNames[props.subjectType], { subjectId: props.subjectId }));
</script>

<template>
    <Button
        v-if="visible"
        as-child
        variant="ghost"
        :size="withLabel ? 'default' : 'icon'"
        :class="withLabel ? 'press h-9 flex-1 gap-1.5 rounded-lg border border-border text-xs font-medium text-muted-foreground' : 'press size-8'"
    >
        <Link :href="href" :aria-label="`History for ${label}`">
            <History class="size-4" />
            <template v-if="withLabel">History</template>
        </Link>
    </Button>
</template>
