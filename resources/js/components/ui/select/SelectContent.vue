<script setup lang="ts">
import type { SelectContentEmits, SelectContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import {
  SelectContent,
  SelectPortal,
  SelectViewport,
  useForwardPropsEmits,
} from "reka-ui"
import { useIsMobile } from "@/composables/useIsMobile"
import { cn } from "@/lib/utils"
import { SelectScrollDownButton, SelectScrollUpButton } from "."

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(
  defineProps<SelectContentProps & { class?: HTMLAttributes["class"] }>(),
  {
    position: "popper",
  },
)
const emits = defineEmits<SelectContentEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)

/*
 * Below the `md` breakpoint a floating dropdown is cramped and easy to
 * mis-tap, so the same content is presented as a bottom sheet instead. The
 * markup is unchanged — reka still owns focus, typeahead and selection — only
 * the placement differs. The `data-sheet` attribute lets app.css pin reka's
 * popper wrapper to the viewport and paint the backdrop.
 */
const isSheet = useIsMobile()
</script>

<template>
  <SelectPortal>
    <SelectContent
      v-bind="{ ...forwarded, ...$attrs }"
      :data-sheet="isSheet ? '' : undefined"
      :class="cn(
        'relative z-50 min-w-32 overflow-hidden border bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
        isSheet
          ? 'w-full max-h-[70dvh] rounded-t-2xl border-x-0 border-b-0 pb-[var(--safe-bottom)] shadow-2xl duration-300 ease-out-quint data-[state=open]:slide-in-from-bottom-full data-[state=closed]:slide-out-to-bottom-full'
          : 'max-h-96 rounded-md shadow-md data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',
        !isSheet && position === 'popper'
          && 'data-[side=bottom]:translate-y-1 data-[side=left]:-translate-x-1 data-[side=right]:translate-x-1 data-[side=top]:-translate-y-1',
        props.class,
      )
      "
    >
      <SelectScrollUpButton />
      <SelectViewport :class="cn(isSheet ? 'p-2' : 'p-1', position === 'popper' && 'h-(--reka-select-trigger-height) w-full min-w-(--reka-select-trigger-width)')">
        <slot />
      </SelectViewport>
      <SelectScrollDownButton />
    </SelectContent>
  </SelectPortal>
</template>
