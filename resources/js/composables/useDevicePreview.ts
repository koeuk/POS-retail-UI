import { onMounted, ref } from 'vue';

/**
 * Preview the app as a phone or tablet without leaving the desktop.
 *
 * This is a genuine viewport, not a scaled screenshot. The shell is rendered
 * inside a frame of the device's width, so the browser's own media queries
 * fire and every `md:` breakpoint — the tab bar, the stacked layouts, the
 * cart sheet — behaves exactly as it does on the real thing.
 *
 * Widths are the CSS-pixel widths the layout is actually designed around.
 */
export type Device = 'desktop' | 'tablet' | 'mobile';

export const DEVICES: Record<Device, { label: string; width: number | null }> = {
    desktop: { label: 'Desktop', width: null }, // fill the window
    tablet: { label: 'Tablet', width: 768 }, // iPad portrait — the `md` breakpoint
    mobile: { label: 'Mobile', width: 390 }, // iPhone 13/14 — the mobile design target
};

const STORAGE_KEY = 'device-preview';

/**
 * True when this document is the copy rendered *inside* the preview iframe.
 *
 * Detected from the window hierarchy, not the URL. Inertia navigates by
 * pushing a new address, so a `?preview=1` flag would be dropped on the very
 * first link click inside the frame — and a reload after that would make the
 * inner page believe it is the outer one and nest a second frame. Whether a
 * document has a parent window never changes, however you navigate.
 */
export const isPreviewFrame: boolean = typeof window !== 'undefined' && window.self !== window.top;

/*
 * Module scope, like useAppearance: the picker lives in the header and the
 * frame lives in the layout, and both must agree the instant one changes.
 */
const device = ref<Device>('desktop');

function isDevice(value: unknown): value is Device {
    return typeof value === 'string' && value in DEVICES;
}

export function useDevicePreview() {
    onMounted(() => {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (isDevice(saved)) device.value = saved;
    });

    function setDevice(value: Device) {
        device.value = value;
        localStorage.setItem(STORAGE_KEY, value);
    }

    return { device, setDevice, devices: DEVICES };
}
