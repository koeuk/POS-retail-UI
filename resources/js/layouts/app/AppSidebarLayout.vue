<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import MobileAppBar from '@/components/MobileAppBar.vue';
import MobileTabBar from '@/components/MobileTabBar.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <!--
            The sidebar stays mounted on a phone but renders nothing there
            (`hidden md:flex` on the Sidebar itself). Unmounting it instead
            would tear down the sidebar context that AppContent's inset reads
            from, and the layout would collapse at the breakpoint.
        -->
        <AppSidebar />

        <!--
            min-w-0 is load-bearing. SidebarInset is a flex child, and a flex
            child's default min-width:auto makes it grow to its widest
            descendant — which silently defeats every overflow-x-auto inside
            and stretches the whole page instead of scrolling one table.
        -->
        <AppContent variant="sidebar" class="min-w-0">
            <!-- One header per breakpoint: breadcrumbs on desktop, app bar on phone. -->
            <!--
                The actions slot is rendered into both headers. Only one is
                ever visible (the classes above are mutually exclusive), and
                the content is presentational, so a duplicate instance costs
                nothing and saves the page from caring which breakpoint it is at.
            -->
            <AppSidebarHeader :breadcrumbs="breadcrumbs" class="hidden md:flex">
                <template #actions><slot name="actions" /></template>
            </AppSidebarHeader>
            <MobileAppBar :breadcrumbs="breadcrumbs">
                <template #actions><slot name="actions" /></template>
            </MobileAppBar>

            <!--
                On a phone the ground goes a shade grey so the white cards read
                as floating above it rather than as panels welded to the page.
                The desktop keeps the bone background, where the sidebar
                already supplies that separation.

                Content clears the fixed tab bar; the desktop has no tab bar
                to clear.
            -->
            <div class="pb-tabbar bg-gradient-to-b from-primary/[0.08] via-muted/40 to-muted/40 md:bg-none md:pb-0">
                <slot />
            </div>
        </AppContent>

        <MobileTabBar />
    </AppShell>
</template>
