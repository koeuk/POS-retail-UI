<script setup lang="ts">
import { useIsMobile } from '@/composables/useIsMobile';
import { cn } from '@/lib/utils';
import { PopoverContent, PopoverPortal, useForwardPropsEmits, type PopoverContentEmits, type PopoverContentProps } from 'reka-ui';
import { computed, type HTMLAttributes } from 'vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<PopoverContentProps & { class?: HTMLAttributes['class'] }>(), {
    align: 'center',
    sideOffset: 4,
});

const emits = defineEmits<PopoverContentEmits>();

const delegated = computed(() => {
    const { class: _, ...rest } = props;

    return rest;
});

const forwarded = useForwardPropsEmits(delegated, emits);

/*
 * On a phone the popover becomes a bottom sheet — same content, same reka
 * behaviour, only the placement changes. `data-sheet` is what app.css keys on
 * to pin the popper wrapper to the viewport and paint the backdrop.
 */
const isSheet = useIsMobile();
</script>

<template>
    <PopoverPortal>
        <PopoverContent
            v-bind="{ ...forwarded, ...$attrs }"
            :data-sheet="isSheet ? '' : undefined"
            :class="
                cn(
                    'z-50 border border-border bg-popover text-popover-foreground outline-none',
                    'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
                    isSheet
                        ? 'w-full max-h-[85dvh] overflow-y-auto rounded-t-2xl border-x-0 border-b-0 p-4 pb-[max(1rem,var(--safe-bottom))] shadow-2xl duration-300 ease-out-quint data-[state=open]:slide-in-from-bottom-full data-[state=closed]:slide-out-to-bottom-full'
                        : 'w-72 rounded-lg p-4 shadow-lg data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',
                    props.class,
                )
            "
        >
            <slot />
        </PopoverContent>
    </PopoverPortal>
</template>
