<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { DateValue } from '@internationalized/date';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import {
    CalendarCell,
    CalendarCellTrigger,
    CalendarGrid,
    CalendarGridBody,
    CalendarGridHead,
    CalendarGridRow,
    CalendarHeadCell,
    CalendarHeader,
    CalendarHeading,
    CalendarNext,
    CalendarPrev,
    CalendarRoot,
} from 'reka-ui';
import type { HTMLAttributes } from 'vue';

/**
 * shadcn's single-date calendar, composed in one file for the same reason as
 * RangeCalendar.vue next door: this app only ever wants the standard layout.
 */
const props = defineProps<{
    class?: HTMLAttributes['class'];
    modelValue?: DateValue;
    maxValue?: DateValue;
    /** Which month opens first. Declared so it is a real prop, not fallthrough. */
    placeholder?: DateValue;
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', value: DateValue | undefined): void;
}>();
</script>

<template>
    <CalendarRoot
        v-slot="{ grid, weekDays }"
        :model-value="props.modelValue"
        :max-value="maxValue"
        :placeholder="placeholder"
        :class="cn('p-3', props.class)"
        @update:model-value="(value) => emits('update:modelValue', value ?? undefined)"
    >
        <CalendarHeader class="relative flex w-full items-center justify-between pt-1">
            <CalendarPrev :class="cn(buttonVariants({ variant: 'outline' }), 'size-7 p-0 opacity-60 hover:opacity-100')">
                <ChevronLeft class="size-4" />
            </CalendarPrev>

            <CalendarHeading class="text-sm font-medium" />

            <CalendarNext :class="cn(buttonVariants({ variant: 'outline' }), 'size-7 p-0 opacity-60 hover:opacity-100')">
                <ChevronRight class="size-4" />
            </CalendarNext>
        </CalendarHeader>

        <CalendarGrid v-for="month in grid" :key="month.value.toString()" class="mt-4 w-full border-collapse select-none space-y-1">
            <CalendarGridHead>
                <CalendarGridRow class="flex">
                    <CalendarHeadCell v-for="day in weekDays" :key="day" class="w-9 rounded-md text-[0.7rem] font-normal text-muted-foreground">
                        {{ day }}
                    </CalendarHeadCell>
                </CalendarGridRow>
            </CalendarGridHead>

            <CalendarGridBody>
                <CalendarGridRow v-for="(weekDates, index) in month.rows" :key="`weekDate-${index}`" class="mt-1 flex w-full">
                    <CalendarCell
                        v-for="weekDate in weekDates"
                        :key="weekDate.toString()"
                        :date="weekDate"
                        class="relative p-0 text-center text-sm focus-within:relative focus-within:z-20"
                    >
                        <CalendarCellTrigger
                            :day="weekDate"
                            :month="month.value"
                            :class="
                                cn(
                                    'relative flex size-9 items-center justify-center whitespace-nowrap rounded-md p-0 text-sm font-normal outline-none transition-colors',
                                    'hover:bg-accent hover:text-accent-foreground',
                                    'data-[selected]:bg-primary data-[selected]:text-primary-foreground data-[selected]:hover:bg-primary',
                                    'data-[today]:font-semibold data-[today]:text-primary data-[today]:data-[selected]:text-primary-foreground',
                                    'data-[outside-view]:text-muted-foreground/40 data-[unavailable]:text-muted-foreground/40 data-[unavailable]:line-through',
                                    'data-[disabled]:pointer-events-none data-[disabled]:opacity-40',
                                )
                            "
                        />
                    </CalendarCell>
                </CalendarGridRow>
            </CalendarGridBody>
        </CalendarGrid>
    </CalendarRoot>
</template>
