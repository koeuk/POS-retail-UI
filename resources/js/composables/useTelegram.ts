import { router } from '@inertiajs/vue3';

/**
 * Telegram Mini App adapter.
 *
 * Only the parts of the WebApp API this build actually needs are typed. The
 * whole module is written so that a normal browser — where `window.Telegram`
 * is absent or `initData` is empty — takes a no-op path rather than a
 * different code path, so there is exactly one shell to reason about.
 */

interface TelegramBackButton {
    show(): void;
    hide(): void;
    onClick(cb: () => void): void;
    offClick(cb: () => void): void;
}

interface TelegramWebApp {
    initData: string;
    version: string;
    platform: string;
    colorScheme: 'light' | 'dark';
    viewportStableHeight?: number;
    viewportHeight?: number;
    isExpanded: boolean;
    safeAreaInset?: { top: number; bottom: number; left: number; right: number };
    contentSafeAreaInset?: { top: number; bottom: number; left: number; right: number };
    BackButton: TelegramBackButton;
    ready(): void;
    expand(): void;
    disableVerticalSwipes?(): void;
    setHeaderColor?(color: string): void;
    setBackgroundColor?(color: string): void;
    onEvent(event: string, cb: () => void): void;
    offEvent(event: string, cb: () => void): void;
    HapticFeedback?: { impactOccurred(style: 'light' | 'medium' | 'heavy'): void };
}

declare global {
    interface Window {
        Telegram?: { WebApp?: TelegramWebApp };
    }
}

const webApp = (): TelegramWebApp | null => window.Telegram?.WebApp ?? null;

/**
 * `window.Telegram` also exists when the script is loaded in a plain browser,
 * so presence alone proves nothing. A non-empty initData string is what
 * actually distinguishes a real Mini App session.
 */
export const isTelegram = (): boolean => {
    const app = webApp();
    return !!app && app.initData.length > 0;
};

/**
 * Telegram reports a viewport height that excludes its own header, and that
 * height changes as the sheet is dragged. `100dvh` does not know about any of
 * it, so the shell reads --app-vh instead and this keeps it honest.
 */
function syncViewport(app: TelegramWebApp) {
    const root = document.documentElement;
    const height = app.viewportStableHeight ?? app.viewportHeight;

    if (height) {
        root.style.setProperty('--app-vh', `${height}px`);
    }

    // Available from Bot API 8.0; older clients simply leave the env() values.
    const safe = app.contentSafeAreaInset ?? app.safeAreaInset;
    if (safe) {
        root.style.setProperty('--safe-top', `${safe.top}px`);
        root.style.setProperty('--safe-bottom', `${safe.bottom}px`);
        root.style.setProperty('--safe-left', `${safe.left}px`);
        root.style.setProperty('--safe-right', `${safe.right}px`);
    }
}

/**
 * Called once from app.ts, before the Vue app mounts. Everything here is
 * global browser state, not component state, so there is nothing to unmount
 * and no reason to pay for it per-component.
 */
export function initTelegram(): void {
    const app = webApp();
    if (!app || !isTelegram()) return;

    app.ready();
    app.expand();

    // Without this a downward drag inside a scrolled list closes the Mini App.
    app.disableVerticalSwipes?.();

    document.documentElement.classList.add('is-telegram');

    syncViewport(app);
    const onResize = () => syncViewport(app);
    app.onEvent('viewportChanged', onResize);
    app.onEvent('safeAreaChanged', onResize);
    app.onEvent('contentSafeAreaChanged', onResize);

    /*
     * Telegram draws its own header above the page, so the app's own back
     * affordance would be the second one on screen. Hand navigation to the
     * native button instead and show it only when there is somewhere to go.
     */
    const back = app.BackButton;
    const goBack = () => window.history.back();
    back.onClick(goBack);

    const syncBackButton = () => {
        const atRoot = window.location.pathname === '/dashboard' || window.location.pathname === '/';
        if (atRoot) {
            back.hide();
        } else {
            back.show();
        }
    };

    syncBackButton();
    router.on('navigate', syncBackButton);
}

/** A short tap response. Silent everywhere except a real Telegram client. */
export function haptic(style: 'light' | 'medium' | 'heavy' = 'light'): void {
    webApp()?.HapticFeedback?.impactOccurred(style);
}
