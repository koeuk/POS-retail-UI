<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { RangeCalendar } from '@/components/ui/range-calendar';
import { useIsMobile } from '@/composables/useIsMobile';
import { CalendarDate, DateFormatter, getLocalTimeZone, parseDate, today, type DateValue } from '@internationalized/date';
import { CalendarDays, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/*
 * Wraps the calendar in the plain 'YYYY-MM-DD' strings the filters already send
 * to the server. CalendarDate carries no time and no zone, so a date chosen in
 * Phnom Penh is the same string the query filters on — which a Date object,
 * serialised through UTC, would quietly shift by a day.
 */
const props = withDefaults(
    defineProps<{
        from?: string;
        to?: string;
        placeholder?: string;
        /** Blocks selecting a range that has not happened yet. */
        futureAllowed?: boolean;
        class?: string;
    }>(),
    { placeholder: 'Any date', futureAllowed: false },
);

const emits = defineEmits<{
    (e: 'update:from', value: string | undefined): void;
    (e: 'update:to', value: string | undefined): void;
}>();

const open = ref(false);

// Two months side by side need ~600px; on a phone the picker is a bottom sheet with room for one.
const isMobile = useIsMobile();

const toDateValue = (value?: string): DateValue | undefined => {
    if (!value) return undefined;

    try {
        return parseDate(value);
    } catch {
        // A hand-edited query string can carry anything; ignore what will not parse.
        return undefined;
    }
};

const range = computed(() => ({ start: toDateValue(props.from), end: toDateValue(props.to) }));

const maxValue = computed(() => (props.futureAllowed ? undefined : today(getLocalTimeZone())));

const formatter = new DateFormatter('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

/** "20 Aug 2026 – 23 Aug 2026", collapsing to one date when both ends match. */
const label = computed(() => {
    const { start, end } = range.value;
    if (!start && !end) return props.placeholder;

    const show = (d?: DateValue) => (d ? formatter.format(d.toDate(getLocalTimeZone())) : '…');

    if (start && end && start.toString() === end.toString()) return show(start);

    return `${show(start)} – ${show(end)}`;
});

const hasRange = computed(() => !!props.from || !!props.to);

function onUpdate(value: { start: DateValue | undefined; end: DateValue | undefined }) {
    emits('update:from', value?.start?.toString());
    emits('update:to', value?.end?.toString());

    // Close once both ends exist; leaving it open after the first click would
    // hide the second half of the range behind the popover.
    if (value?.start && value?.end) open.value = false;
}

function clear(event: Event) {
    event.stopPropagation();
    emits('update:from', undefined);
    emits('update:to', undefined);
}

/** Keeps the calendar honest about which month to open on. */
const placeholderDate = computed<DateValue>(() => range.value.start ?? (today(getLocalTimeZone()) as CalendarDate));
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                :class="['justify-start gap-2 font-normal', hasRange ? '' : 'text-muted-foreground', props.class]"
                :aria-label="hasRange ? `Date range: ${label}` : 'Choose a date range'"
            >
                <CalendarDays class="size-4 shrink-0" />
                <span class="truncate">{{ label }}</span>

                <span
                    v-if="hasRange"
                    role="button"
                    tabindex="0"
                    aria-label="Clear date range"
                    class="-mr-1 ml-auto rounded-sm p-0.5 text-muted-foreground transition-colors hover:text-foreground"
                    @click="clear"
                    @keydown.enter.stop.prevent="clear"
                    @keydown.space.stop.prevent="clear"
                >
                    <X class="size-3.5" />
                </span>
            </Button>
        </PopoverTrigger>

        <PopoverContent :class="isMobile ? 'flex flex-col items-center p-0 pb-[var(--safe-bottom)]' : 'w-auto p-0'" align="end">
            <RangeCalendar
                :model-value="range"
                :placeholder="placeholderDate"
                :max-value="maxValue"
                :number-of-months="isMobile ? 1 : 2"
                @update:model-value="onUpdate"
            />
        </PopoverContent>
    </Popover>
</template>
