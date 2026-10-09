import { nextTick } from 'vue';

/**
 * Prints whichever `.receipt-sheet` is currently mounted.
 *
 * The print stylesheet keys off a body class rather than opening a new
 * window: a popup would need to re-fetch styles and fonts, which is exactly
 * what is unavailable when the till is offline. Everything needed is already
 * on this page.
 */
export function printReceipt(): Promise<void> {
    return nextTick().then(() => {
        document.body.classList.add('printing-receipt');

        const cleanup = () => {
            document.body.classList.remove('printing-receipt');
            window.removeEventListener('afterprint', cleanup);
        };

        window.addEventListener('afterprint', cleanup);

        // Safari and some Android WebViews never fire afterprint; the timeout
        // guarantees the class is removed and the UI is not left invisible.
        setTimeout(cleanup, 3000);

        window.print();
    });
}
