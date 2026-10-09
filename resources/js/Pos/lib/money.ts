/**
 * Client-side twin of App\Services\OrderTotals.
 *
 * These two implementations MUST agree to the cent. The cashier hands over a
 * printed receipt at the till; the server recomputes the same sale hours later
 * when the queue flushes. If the algorithms drift, the paper and the database
 * disagree and nobody can tell which one is right.
 *
 * Same two steps as the server:
 *   1. the line discount comes off each line
 *   2. the order discount comes off the sum
 *
 * No tax: a price is what the customer pays. All arithmetic is in the shop
 * currency's integer minor unit — cents for dollars, whole riel for riel —
 * because floats drift and a till adds thousands of lines a day.
 */

export interface TotalsLine {
    qty: number;
    unitPrice: number;
    discount: number;
}

export interface Totals {
    subtotal: number;
    discount: number;
    total: number;
    lineSubtotals: number[];
}

const toMinor = (value: number | string | null | undefined, factor: number): number => Math.round(Number(value ?? 0) * factor);

/**
 * `factor` is the currency's minor unit — 100 for dollars, 1 for riel. It has
 * to be passed in rather than assumed: hard-coding 100 quantised every riel
 * price to the nearest 40៛.
 */
export function computeTotals(lines: TotalsLine[], orderDiscount = 0, factor = 100): Totals {
    const nets = lines.map((line) => Math.max(0, toMinor(line.unitPrice, factor) * line.qty - toMinor(line.discount, factor)));

    const subtotal = nets.reduce((sum, n) => sum + n, 0);

    // Never below zero, however large the number typed into the discount box.
    const discount = Math.min(toMinor(orderDiscount, factor), subtotal);
    const fromMinor = (minor: number) => minor / factor;

    return {
        subtotal: fromMinor(subtotal),
        discount: fromMinor(discount),
        total: fromMinor(subtotal - discount),
        lineSubtotals: nets.map(fromMinor),
    };
}

/** Re-exported so POS components format prices exactly like the rest of the app. */
export { formatCurrency as formatMoney } from '@/composables/useCurrency';

/** Laravel decimal columns arrive as strings; keep that shape going back. */
export const toDecimalString = (value: number, decimals = 2): string => value.toFixed(decimals);
