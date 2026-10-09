import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Mirror of App\Support\Currency.
 *
 * Amounts are stored in the shop's own currency, so nothing is converted here
 * — this only decides how a number looks. Money used to be kept in dollars and
 * multiplied on the way out, which could not express riel: a US cent is 40៛,
 * so a 500៛ price became 13 cents and came back as 520៛.
 */
export interface CurrencyDef {
    code: string;
    symbol: string;
    /** 2 for dollars, 0 for riel — riel has no fractional unit. */
    decimals: number;
    riel_per_usd: number;
}

export const USD: CurrencyDef = { code: 'USD', symbol: '$', decimals: 2, riel_per_usd: 4100 };

/** How many minor units make one whole one: 100 for dollars, 1 for riel. */
export const minorFactor = (def: CurrencyDef): number => 10 ** def.decimals;

/** "$4.00" or "៛16,400". The one place a price becomes a string. */
export function formatCurrency(amount: number | string | null | undefined, def: CurrencyDef): string {
    const value = Number(amount ?? 0);

    return (
        def.symbol +
        (Number.isFinite(value) ? value : 0).toLocaleString(undefined, {
            minimumFractionDigits: def.decimals,
            maximumFractionDigits: def.decimals,
        })
    );
}

/**
 * The shop currency for the current page, from the Inertia shared props.
 * Pages that already carry a `currency` prop (POS, order detail, menu) can
 * pass it explicitly instead; the shape is the same.
 */
export function useCurrency(override?: () => CurrencyDef | undefined) {
    const page = usePage<SharedData>();

    const currency = computed<CurrencyDef>(() => override?.() ?? page.props.currency ?? USD);

    const money = (amount: number | string | null | undefined) => formatCurrency(amount, currency.value);

    return { currency, money };
}
