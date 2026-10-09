<script setup lang="ts">
import type { CurrencyDef } from '@/composables/useCurrency';
import { useCart } from '@/Pos/composables/useCart';
import { formatMoney } from '@/Pos/lib/money';
import type { SaleType } from '@/Pos/types';
import { CreditCard, HandCoins, UserRound, Utensils } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{ currency: CurrencyDef }>();
const emit = defineEmits<{ pay: []; 'pick-customer': [] }>();

const cart = useCart();

/*
 * Three reasons goods leave the shelf, and they mean different things for
 * the money — see App\Enums\SaleType. The chips sit above the total because
 * a cashier decides *why* before deciding *how much*.
 */
const types: { value: SaleType; label: string; icon: typeof UserRound; hint: string }[] = [
    { value: 'customer', label: 'Customer', icon: UserRound, hint: 'A normal sale' },
    { value: 'debt', label: 'In debt', icon: HandCoins, hint: 'Pay later — needs a name' },
    { value: 'myself', label: 'Myself', icon: Utensils, hint: 'Not a sale' },
];

function choose(t: SaleType) {
    cart.saleType = t;
    // A debt is unusable without someone to owe it, so ask straight away.
    if (t === 'debt' && !cart.customerId) emit('pick-customer');
}

/* A debt with nobody attached must not be completable. */
const blocked = computed(() => cart.saleType === 'debt' && !cart.customerId);

const cta = computed(() => {
    switch (cart.saleType) {
        case 'debt':
            return blocked.value ? 'Choose who owes this' : 'Record debt';
        case 'myself':
            return 'Take for myself';
        default:
            return 'Complete sale';
    }
});
</script>

<template>
    <!--
        One number at the till. A cashier reads the figure the customer has to
        pay and nothing else; the line-by-line breakdown belongs on the receipt
        and in Reports, where someone is actually reconciling.
    -->
    <div class="shrink-0 border-t border-border bg-card">
        <div class="space-y-3 p-4">
            <div class="grid grid-cols-3 gap-1.5">
                <button
                    v-for="t in types"
                    :key="t.value"
                    type="button"
                    class="press flex flex-col items-center gap-1 rounded-lg border px-2 py-2 transition-colors"
                    :class="cart.saleType === t.value ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground'"
                    :title="t.hint"
                    :aria-pressed="cart.saleType === t.value"
                    @click="choose(t.value)"
                >
                    <component :is="t.icon" class="size-4" />
                    <span class="text-xs font-medium leading-none">{{ t.label }}</span>
                </button>
            </div>

            <!-- Who the debt is on; tap to change. -->
            <button
                v-if="cart.saleType === 'debt'"
                type="button"
                class="press flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm"
                :class="blocked ? 'border-destructive/50 text-destructive' : 'border-border'"
                @click="emit('pick-customer')"
            >
                <UserRound class="size-4 shrink-0" />
                <span class="min-w-0 flex-1 truncate">{{ cart.customerName ?? 'No customer chosen' }}</span>
                <span class="text-xs text-muted-foreground">Change</span>
            </button>

            <div class="flex items-baseline justify-between">
                <p class="font-display text-base font-semibold">{{ cart.saleType === 'myself' ? 'Value' : 'Total' }}</p>
                <p class="tabular font-mono text-2xl font-bold text-primary">
                    {{ formatMoney(cart.totals.total, currency) }}
                </p>
            </div>

            <button
                type="button"
                :disabled="cart.isEmpty || blocked"
                class="press flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-primary text-base font-semibold text-primary-foreground disabled:opacity-40"
                @click="emit('pay')"
            >
                <CreditCard class="size-5" />
                {{ cta }}
            </button>
        </div>
    </div>
</template>
