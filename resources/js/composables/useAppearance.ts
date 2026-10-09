import { onMounted, ref } from 'vue';

type Appearance = 'light' | 'dark';

/*
 * Two themes, chosen by hand. There used to be a third, "system", that
 * followed the OS — it was removed as more confusing than helpful on shared
 * shop tablets, where "why did the screen change by itself" is a support
 * question. Whatever was stored before (including 'system', or nothing at
 * all) resolves once to what the OS currently shows and sticks there, so
 * nobody's screen flips theme on upgrade.
 */
const resolve = (saved: string | null): Appearance =>
    saved === 'light' || saved === 'dark' ? saved : window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';

export function updateTheme(value: Appearance) {
    document.documentElement.classList.toggle('dark', value === 'dark');
}

export function initializeTheme() {
    updateTheme(resolve(localStorage.getItem('appearance')));
}

/*
 * Module scope, not per-call: the toggle appears in both the desktop header
 * and the mobile app bar, and the settings page reads the same value. A ref
 * created per caller would let those drift — change the theme on one, resize
 * past the breakpoint, and the other still shows the old icon.
 */
const appearance = ref<Appearance>('light');

export function useAppearance() {
    onMounted(() => {
        appearance.value = resolve(localStorage.getItem('appearance'));
        updateAppearance(appearance.value);
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;
        localStorage.setItem('appearance', value);
        updateTheme(value);
    }

    return {
        appearance,
        updateAppearance,
    };
}
