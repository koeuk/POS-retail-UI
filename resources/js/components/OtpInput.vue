<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';

const props = withDefaults(defineProps<{ modelValue: string; length?: number; invalid?: boolean; disabled?: boolean }>(), {
    length: 6,
    invalid: false,
    disabled: false,
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    /** Fires the moment the last box is filled, so the form can self-submit. */
    (e: 'complete', value: string): void;
}>();

const boxes = ref<HTMLInputElement[]>([]);

/** One box per character, padded out so v-for always renders `length` inputs. */
const digits = ref<string[]>(Array.from({ length: props.length }, (_, i) => props.modelValue[i] ?? ''));

// The parent clears the code after a failed attempt; mirror that back.
watch(
    () => props.modelValue,
    (value) => {
        if (value === digits.value.join('')) return;
        digits.value = Array.from({ length: props.length }, (_, i) => value[i] ?? '');
    },
);

function publish() {
    const value = digits.value.join('');
    emit('update:modelValue', value);

    if (value.length === props.length) emit('complete', value);
}

function focusBox(index: number) {
    const box = boxes.value[Math.min(Math.max(index, 0), props.length - 1)];
    box?.focus();
    box?.select();
}

function onInput(index: number, event: Event) {
    const input = event.target as HTMLInputElement;
    // A numeric keypad can still deliver punctuation, and Android autofill can
    // drop the whole code into one box — keep the digits, spread them out.
    const typed = input.value.replace(/\D/g, '');

    if (!typed) {
        digits.value[index] = '';
        input.value = '';
        publish();

        return;
    }

    for (let i = 0; i < typed.length && index + i < props.length; i++) {
        digits.value[index + i] = typed[i];
    }

    input.value = digits.value[index];
    publish();
    focusBox(index + typed.length);
}

function onKeydown(index: number, event: KeyboardEvent) {
    if (event.key === 'Backspace') {
        // Backspace in an empty box steps back and clears the previous one,
        // which is what every native code field does.
        if (digits.value[index]) {
            digits.value[index] = '';
            publish();

            return;
        }

        event.preventDefault();
        digits.value[index - 1] = '';
        publish();
        focusBox(index - 1);

        return;
    }

    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        focusBox(index - 1);
    }

    if (event.key === 'ArrowRight') {
        event.preventDefault();
        focusBox(index + 1);
    }
}

function onPaste(index: number, event: ClipboardEvent) {
    const pasted = event.clipboardData?.getData('text')?.replace(/\D/g, '') ?? '';
    if (!pasted) return;

    event.preventDefault();

    for (let i = 0; i < pasted.length && index + i < props.length; i++) {
        digits.value[index + i] = pasted[i];
    }

    publish();
    nextTick(() => focusBox(index + pasted.length));
}

defineExpose({ focus: () => focusBox(0) });
</script>

<template>
    <div class="flex justify-center gap-2" dir="ltr">
        <input
            v-for="(digit, index) in digits"
            :key="index"
            ref="boxes"
            :value="digit"
            type="text"
            inputmode="numeric"
            :autocomplete="index === 0 ? 'one-time-code' : 'off'"
            maxlength="1"
            :disabled="disabled"
            :aria-label="`Digit ${index + 1} of ${length}`"
            class="h-14 w-11 rounded-lg border bg-background text-center font-mono text-xl font-semibold tabular-nums transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-background disabled:opacity-50 sm:w-12"
            :class="invalid ? 'border-destructive' : 'border-input'"
            @input="onInput(index, $event)"
            @keydown="onKeydown(index, $event)"
            @paste="onPaste(index, $event)"
            @focus="($event.target as HTMLInputElement).select()"
        />
    </div>
</template>
