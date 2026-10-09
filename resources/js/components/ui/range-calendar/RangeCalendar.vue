<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { DateValue } from '@internationalized/date';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import type { DateRange } from 'reka-ui';
import {
    RangeCalendarCell,
    RangeCalendarCellTrigger,
    RangeCalendarGrid,
    RangeCalendarGridBody,
    RangeCalendarGridHead,
    RangeCalendarGridRow,
    RangeCalendarHeadCell,
    RangeCalendarHeader,
    RangeCalendarHeading,
    RangeCalendarNext,
    RangeCalendarPrev,
    RangeCalendarRoot,
} from 'reka-ui';
import type { HTMLAttributes } from 'vue';

/**
 * shadcn's range calendar, composed in one file rather than the usual dozen.
 * Every subcomponent exists so a caller can rearrange the layout; this app
 * only ever wants the standard one, and splitting it would spread a single
 * grid across twelve files that always change together.
 */
const props = defineProps<{
    class?: HTMLAttributes['class'];
    modelValue?: DateRange;
    numberOfMonths?: number;
    maxValue?: DateValue;
    /** Which month opens first. Declared so it is a real prop, not fallthrough. */
    placeholder?: DateValue;
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', value: DateRange): void;
}>();
</script>

<template>
    <RangeCalendarRoot
        v-slot="{ grid, weekDays }"
        :model-value="props.modelValue"
        :number-of-months="numberOfMonths ?? 1"
        :max-value="maxValue"
        :placeholder="placeholder"
        :class="cn('p-3', props.class)"
        @update:model-value="(value: DateRange) => emits('update:modelValue', value)"
    >
        <RangeCalendarHeader class="relative flex w-full items-center justify-between pt-1">
            <RangeCalendarPrev :class="cn(buttonVariants({ variant: 'outline' }), 'size-7 p-0 opacity-60 hover:opacity-100')">
                <ChevronLeft class="size-4" />
            </RangeCalendarPrev>

            <RangeCalendarHeading class="text-sm font-medium" />

            <RangeCalendarNext :class="cn(buttonVariants({ variant: 'outline' }), 'size-7 p-0 opacity-60 hover:opacity-100')">
                <ChevronRight class="size-4" />
            </RangeCalendarNext>
        </RangeCalendarHeader>

        <div class="mt-4 flex flex-col gap-4 sm:flex-row">
            <RangeCalendarGrid v-for="month in grid" :key="month.value.toString()" class="w-full border-collapse select-none space-y-1">
                <RangeCalendarGridHead>
                    <RangeCalendarGridRow class="flex">
                        <RangeCalendarHeadCell v-for="day in weekDays" :key="day" class="w-9 rounded-md text-[0.7rem] font-normal text-muted-foreground">
                            {{ day }}
                        </RangeCalendarHeadCell>
                    </RangeCalendarGridRow>
                </RangeCalendarGridHead>

                <RangeCalendarGridBody>
                    <RangeCalendarGridRow v-for="(weekDates, index) in month.rows" :key="`weekDate-${index}`" class="mt-1 flex w-full">
                        <RangeCalendarCell
                            v-for="weekDate in weekDates"
                            :key="weekDate.toString()"
                            :date="weekDate"
                            class="relative p-0 text-center text-sm focus-within:relative focus-within:z-20"
                        >
                            <!--
                                The middle of a range is a filled block, so its
                                corners are squared off; only the two ends keep
                                a radius. That is what makes a run of days read
                                as one selection rather than seven chips.
                            -->
                            <RangeCalendarCellTrigger
                                :day="weekDate"
                                :month="month.value"
                                :class="
                                    cn(
                                        'relative flex size-9 items-center justify-center whitespace-nowrap rounded-md p-0 text-sm font-normal outline-none transition-colors',
                                        'hover:bg-accent hover:text-accent-foreground',
                                        'data-[selected]:bg-accent data-[selected]:text-accent-foreground',
                                        'data-[selection-start]:rounded-l-md data-[selection-start]:bg-primary data-[selection-start]:text-primary-foreground data-[selection-start]:hover:bg-primary',
                                        'data-[selection-end]:rounded-r-md data-[selection-end]:bg-primary data-[selection-end]:text-primary-foreground data-[selection-end]:hover:bg-primary',
                                        'data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground',
                                        'data-[today]:font-semibold data-[today]:text-primary',
                                        'data-[outside-view]:text-muted-foreground/40 data-[unavailable]:text-muted-foreground/40 data-[unavailable]:line-through',
                                        'data-[disabled]:pointer-events-none data-[disabled]:opacity-40',
                                    )
                                "
                            />
                        </RangeCalendarCell>
                    </RangeCalendarGridRow>
                </RangeCalendarGridBody>
            </RangeCalendarGrid>
        </div>
    </RangeCalendarRoot>
</template>
