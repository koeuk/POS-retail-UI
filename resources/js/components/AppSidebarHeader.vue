<script setup lang="ts">
import DevicePreviewSelect from '@/components/DevicePreviewSelect.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { isPreviewFrame } from '@/composables/useDevicePreview';
import { cn } from '@/lib/utils';
import type { BreadcrumbItemType } from '@/types';
import type { HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItemType[];
        class?: HTMLAttributes['class'];
    }>(),
    { breadcrumbs: () => [] },
);
</script>

<template>
    <header
        :class="
            cn(
                'flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/10 px-6 transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12 md:px-4',
                props.class,
            )
        "
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs.length > 0">
                <Breadcrumb>
                    <BreadcrumbList>
                        <template v-for="(item, index) in breadcrumbs" :key="index">
                            <BreadcrumbItem>
                                <template v-if="index === breadcrumbs.length - 1">
                                    <BreadcrumbPage>{{ item.title }}</BreadcrumbPage>
                                </template>
                                <template v-else>
                                    <BreadcrumbLink :href="item.href">
                                        {{ item.title }}
                                    </BreadcrumbLink>
                                </template>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" />
                        </template>
                    </BreadcrumbList>
                </Breadcrumb>
            </template>
        </div>

        <!-- Page-supplied chrome (a sync badge, a register picker) sits with
             the breadcrumb rather than inside the page body, so it stays put
             while the content below it scrolls. -->
        <div class="ml-auto flex items-center gap-2">
            <slot name="actions" />

            <!--
                Device preview. Hidden inside the preview frame itself — the
                frame is already the device, and a picker in there would nest
                frames forever.
            -->
            <DevicePreviewSelect v-if="!isPreviewFrame" />

            <!-- Always last: page chrome comes and goes, but the theme control
                 is in the same place on every screen. -->
            <ThemeToggle class="text-muted-foreground hover:bg-accent hover:text-foreground" />
        </div>
    </header>
</template>
