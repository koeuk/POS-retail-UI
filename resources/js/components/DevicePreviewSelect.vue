<script setup lang="ts">
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type Device, useDevicePreview } from '@/composables/useDevicePreview';
import { Monitor, Smartphone, Tablet } from 'lucide-vue-next';
import { computed } from 'vue';

const { device, setDevice, devices } = useDevicePreview();

const icons: Record<Device, typeof Monitor> = { desktop: Monitor, tablet: Tablet, mobile: Smartphone };
const Icon = computed(() => icons[device.value]);

/* Select emits the raw string; narrow it before it reaches the store. */
function onChange(value: unknown) {
    if (typeof value === 'string' && value in devices) setDevice(value as Device);
}
</script>

<template>
    <!--
        Desktop only. On a real phone or tablet the frame would be narrower
        than the screen it is already on, which is nonsense — and the header
        slot it sits in is hidden there anyway.
    -->
    <Select :model-value="device" @update:model-value="onChange">
        <SelectTrigger class="h-9 w-[9.5rem] gap-2 text-xs" aria-label="Preview as device">
            <Icon class="size-4 shrink-0 text-muted-foreground" />
            <SelectValue />
        </SelectTrigger>
        <SelectContent align="end">
            <SelectItem v-for="(meta, key) in devices" :key="key" :value="key">
                <span class="flex items-center gap-2">
                    <component :is="icons[key]" class="size-4 text-muted-foreground" />
                    {{ meta.label }}
                    <span v-if="meta.width" class="tabular font-mono text-[0.65rem] text-muted-foreground">{{ meta.width }}px</span>
                </span>
            </SelectItem>
        </SelectContent>
    </Select>
</template>
