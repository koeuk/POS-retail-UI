<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue';
import ProductForm from '@/components/ProductForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Category } from '@/types';
import { Head } from '@inertiajs/vue3';

defineProps<{ categories: Category[] }>();
</script>

<template>
    <Head title="New product" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Products', href: '/products' },
            { title: 'New', href: '/products/create' },
        ]"
    >
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader title="New product" description="Add an item to the catalogue. It appears on the POS grid as soon as it is active." />

            <ProductForm :categories="categories" />
        </div>
    </AppLayout>
</template>
