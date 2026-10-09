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
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Category } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Shapes, Trash2 } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{
    categories: Category[];
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
            route('categories.index'),
            { filter: { search: search.value || undefined } },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

const editing = ref<Category | null>(null);
const dialogOpen = ref(false);

const form = useForm({ name: '' });

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(category: Category) {
    editing.value = category;
    form.clearErrors();
    form.name = category.name;
    dialogOpen.value = true;
}

function submit() {
    const payload = { onSuccess: () => (dialogOpen.value = false), preserveScroll: true };

    if (editing.value) {
        form.put(route('categories.update', { category: editing.value.uuid }), payload);
    } else {
        form.post(route('categories.store'), payload);
    }
}

const pendingDelete = ref<Category | null>(null);

function confirmDelete() {
    if (!pendingDelete.value) return;
    router.delete(route('categories.destroy', { category: pendingDelete.value.uuid }), {
        preserveScroll: true,
        onFinish: () => (pendingDelete.value = null),
    });
}
</script>

<template>
    <Head title="Categories" />

    <AppLayout :breadcrumbs="[{ title: 'Categories', href: '/categories' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader title="Categories" description="Used to group products and filter the POS grid.">
                <template #actions>
                    <Button v-if="may('categories', 'create')" class="press hidden md:inline-flex" @click="openCreate">
                        <Plus class="size-4" />
                        New category
                    </Button>
                </template>
            </PageHeader>

            <div class="list-panel animate-rise" style="animation-delay: 60ms">
                <div class="border-b border-border p-3">
                    <div class="relative max-w-sm">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Search categories…" class="h-10 rounded-full pl-9" autocomplete="off" />
                    </div>
                </div>

                <ul v-if="categories.length">
                    <li v-for="c in categories" :key="c.id" class="list-row group">
                        <!-- The row itself is the edit affordance — on a phone a
                             pencil hiding behind a hover state is unreachable. -->
                        <button
                            type="button"
                            class="list-row-main md:px-4"
                            :disabled="!may('categories', 'update')"
                            :aria-label="may('categories', 'update') ? `Edit ${c.name}` : c.name"
                            @click="openEdit(c)"
                        >
                            <Shapes class="size-4 shrink-0 text-primary" />

                            <p class="min-w-0 flex-1 truncate font-medium leading-tight">{{ c.name }}</p>

                            <Badge variant="outline" class="tabular shrink-0 font-mono">
                                {{ c.products_count ?? 0 }} product{{ c.products_count === 1 ? '' : 's' }}
                            </Badge>

                            <Pencil class="hidden size-4 shrink-0 text-muted-foreground md:block" />
                        </button>

                        <div class="flex shrink-0 items-center">
                            <HistoryButton subject-type="Category" :subject-id="c.uuid" :label="c.name" />
                        </div>

                        <button
                            v-if="may('categories', 'delete')"
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
                    :icon="Shapes"
                    title="No categories yet"
                    description="Categories group products so cashiers can find them quickly."
                >
                    <Button variant="outline" class="press" @click="openCreate">Add a category</Button>
                </EmptyState>
            </div>
        </div>

        <!-- Phone: create floats bottom-right above the tab bar, in thumb reach. -->
        <Button
            v-if="may('categories', 'create')"
            class="press fixed right-4 z-40 h-12 rounded-full px-5 shadow-lg md:hidden"
            style="bottom: calc(var(--tabbar-h) + var(--safe-bottom) + 1rem)"
            @click="openCreate"
        >
            <Plus class="size-5" />
            New category
        </Button>

        <!-- Create / edit -->
        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <form @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{ editing ? 'Edit category' : 'New category' }}</DialogTitle>
                        <DialogDescription> Categories group products so cashiers can find them quickly. </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-2">
                            <Label for="cat-name">Name</Label>
                            <Input id="cat-name" v-model="form.name" required autofocus />
                            <InputError :message="form.errors.name" />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="dialogOpen = false"> Cancel </Button>
                        <Button type="submit" class="press" :disabled="form.processing">
                            {{ editing ? 'Save' : 'Create' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Delete -->
        <Dialog :open="!!pendingDelete" @update:open="(v) => !v && (pendingDelete = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete “{{ pendingDelete?.name }}”?</DialogTitle>
                    <DialogDescription> A category that still holds products cannot be deleted. </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" class="press" @click="pendingDelete = null">Cancel</Button>
                    <Button class="press bg-destructive text-destructive-foreground hover:bg-destructive/90" @click="confirmDelete"> Delete </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
