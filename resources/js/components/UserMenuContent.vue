<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import type { User } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import { LogOut, Settings } from 'lucide-vue-next';

interface Props {
    user: User;
}

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true" @select="router.visit(route('profile.edit'))">
            <Link class="flex w-full items-center" :href="route('profile.edit')">
                <Settings class="mr-2 h-4 w-4" />
                <span>Settings</span>
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true" @select="router.post(route('logout'))">
        <Link class="flex w-full items-center" method="post" :href="route('logout')" as="button">
            <LogOut class="mr-2 h-4 w-4" />
            <span>Log out</span>
        </Link>
    </DropdownMenuItem>
</template>
