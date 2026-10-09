import { useMediaQuery } from '@vueuse/core';

/**
 * True below Tailwind's `md` breakpoint — the same line the tab bar, the
 * stacked layouts and the bottom-sheet overlays switch on. Kept in one place
 * so a template's `md:` classes and its script never disagree.
 */
export function useIsMobile() {
    return useMediaQuery('(max-width: 767px)');
}
