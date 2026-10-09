import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Keyboard-wedge barcode scanner support.
 *
 * These scanners are not cameras — they emulate a keyboard, typing the code
 * far faster than a person can and finishing with Enter. So the only reliable
 * way to tell a scan from typing is the gap between keystrokes.
 *
 * The listener sits on the document rather than an input, because the cashier
 * should be able to scan at any moment without first clicking a field.
 */

interface Options {
    onScan: (code: string) => void;
    /** Max milliseconds between keystrokes for input to count as a scan. */
    maxGap?: number;
    /** Shortest string worth treating as a barcode. */
    minLength?: number;
}

export function useBarcode({ onScan, maxGap = 40, minLength = 4 }: Options) {
    let buffer = '';
    let lastAt = 0;

    function handler(event: KeyboardEvent) {
        /*
         * Ignore anything typed into a real field. A cashier searching the
         * product grid types slowly enough that the gap check would catch it
         * anyway, but a fast typist in the discount box should never have
         * their input swallowed.
         */
        const target = event.target as HTMLElement | null;
        if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) {
            return;
        }

        const now = Date.now();

        if (now - lastAt > maxGap) {
            buffer = '';
        }
        lastAt = now;

        if (event.key === 'Enter') {
            const code = buffer.trim();
            buffer = '';

            if (code.length >= minLength) {
                // Stop the Enter from also submitting whatever has focus.
                event.preventDefault();
                onScan(code);
            }
            return;
        }

        // Scanners emit plain printable characters; ignore modifiers and
        // navigation keys so a stray Shift never breaks a code in half.
        if (event.key.length === 1) {
            buffer += event.key;
        }
    }

    onMounted(() => document.addEventListener('keydown', handler));
    onBeforeUnmount(() => document.removeEventListener('keydown', handler));
}
