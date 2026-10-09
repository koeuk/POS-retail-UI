<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useIsMobile } from '@/composables/useIsMobile';
import { CalendarDate, DateFormatter, getLocalTimeZone, parseDate, today, type DateValue } from '@internationalized/date';
import { CalendarDays } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/*
 * One day, as the plain 'YYYY-MM-DD' string the filters already send to the
 * server — the single-date sibling of DateRangePicker, for screens that ask
 * "which day?" rather than "which stretch?". CalendarDate carries no time and
 * no zone, so the chosen day cannot shift crossing UTC.
 */
const props = withDefaults(
    defineProps<{
        modelValue?: string;
        placeholder?: string;
        /** Blocks selecting a day that has not happened yet. */
        futureAllowed?: boolean;
        class?: string;
        ariaLabel?: string;
    }>(),
    { placeholder: 'Pick a date', futureAllowed: false },
);

const emits = defineEmits<{
    (e: 'update:modelValue', value: string | undefined): void;
}>();

const open = ref(false);
const isMobile = useIsMobile();

const selected = computed<DateValue | undefined>(() => {
    if (!props.modelValue) return undefined;

    try {
        return parseDate(props.modelValue);
    } catch {
        // A hand-edited query string can carry anything; ignore what will not parse.
        return undefined;
    }
});

const maxValue = computed(() => (props.futureAllowed ? undefined : today(getLocalTimeZone())));

const formatter = new DateFormatter('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

const label = computed(() => (selected.value ? formatter.format(selected.value.toDate(getLocalTimeZone())) : props.placeholder));

function onUpdate(value: DateValue | undefined) {
    emits('update:modelValue', value?.toString());
    if (value) open.value = false;
}

/** Keeps the calendar honest about which month to open on. */
const placeholderDate = computed<DateValue>(() => selected.value ?? (today(getLocalTimeZone()) as CalendarDate));
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                :class="['justify-start gap-2 font-normal', selected ? '' : 'text-muted-foreground', props.class]"
                :aria-label="ariaLabel ?? (selected ? `Date: ${label}` : 'Choose a date')"
            >
                <CalendarDays class="size-4 shrink-0" />
                <span class="truncate">{{ label }}</span>
            </Button>
        </PopoverTrigger>

        <PopoverContent :class="isMobile ? 'flex flex-col items-center p-0 pb-[var(--safe-bottom)]' : 'w-auto p-0'" align="end">
            <Calendar :model-value="selected" :placeholder="placeholderDate" :max-value="maxValue" @update:model-value="onUpdate" />
        </PopoverContent>
    </Popover>
</template>
