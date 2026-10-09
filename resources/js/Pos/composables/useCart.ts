import { minorFactor, useCurrency } from '@/composables/useCurrency';
import { computeTotals } from '@/Pos/lib/money';
import type { CartLine, PosProduct, SaleType } from '@/Pos/types';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

/**
 * The cart lives entirely in memory. Nothing here touches the network — a
 * sale is only ever persisted when the cashier completes it.
 */
export const useCart = defineStore('pos-cart', () => {
    const lines = ref<CartLine[]>([]);
    const orderDiscount = ref(0);
    const customerId = ref<number | null>(null);
    const customerName = ref<string | null>(null);

    /*
     * Why the goods are leaving. Defaults to an ordinary customer sale and
     * resets with the cart, so a "myself" flag can never leak into the next
     * customer's order by accident.
     */
    const saleType = ref<SaleType>('customer');

    /*
     * The shop's minor unit drives the arithmetic. Riel has none, so its
     * factor is 1 — assuming cents quantised every riel price to the nearest
     * 40៛ and put the printed receipt at odds with the server's total.
     */
    const { currency } = useCurrency();

    const totals = computed(() =>
        computeTotals(
            lines.value.map((l) => ({
                qty: l.qty,
                unitPrice: l.unitPrice,
                discount: l.discount,
            })),
            orderDiscount.value,
            minorFactor(currency.value),
        ),
    );

    const count = computed(() => lines.value.reduce((sum, l) => sum + l.qty, 0));
    const isEmpty = computed(() => lines.value.length === 0);

    function add(product: PosProduct, qty = 1) {
        const existing = lines.value.find((l) => l.productId === product.id);

        // Scanning the same barcode twice bumps the quantity rather than
        // stacking duplicate lines — that is what a till is expected to do.
        if (existing) {
            existing.qty += qty;
            return;
        }

        lines.value.push({
            productId: product.id,
            name: product.name,
            unitPrice: Number(product.sell_price),
            qty,
            discount: 0,
            unit: product.unit,
            trackStock: product.track_stock,
            stockHint: product.stock_qty,
        });
    }

    function setQty(productId: number, qty: number) {
        const line = lines.value.find((l) => l.productId === productId);
        if (!line) return;

        if (qty <= 0) {
            remove(productId);
            return;
        }

        line.qty = qty;
    }

    function setLineDiscount(productId: number, amount: number) {
        const line = lines.value.find((l) => l.productId === productId);
        if (!line) return;

        // A line discount can never exceed the line itself.
        line.discount = Math.max(0, Math.min(amount, line.unitPrice * line.qty));
    }

    function remove(productId: number) {
        lines.value = lines.value.filter((l) => l.productId !== productId);
    }

    function setOrderDiscount(amount: number) {
        orderDiscount.value = Math.max(0, amount);
    }

    function clear() {
        lines.value = [];
        orderDiscount.value = 0;
        customerId.value = null;
        customerName.value = null;
        saleType.value = 'customer';
    }

    return {
        lines,
        orderDiscount,
        customerId,
        customerName,
        saleType,
        totals,
        count,
        isEmpty,
        add,
        setQty,
        setLineDiscount,
        remove,
        setOrderDiscount,
        clear,
    };
});
