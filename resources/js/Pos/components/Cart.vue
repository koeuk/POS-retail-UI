<script setup lang="ts">
import type { CurrencyDef } from '@/composables/useCurrency';
import { useCart } from '@/Pos/composables/useCart';
import { formatMoney } from '@/Pos/lib/money';
import { Minus, Plus, ShoppingCart, Trash2 } from 'lucide-vue-next';

defineProps<{ currency: CurrencyDef }>();

const cart = useCart();
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="flex shrink-0 items-center justify-between border-b border-border px-4 py-3">
            <h2 class="flex items-center gap-2 font-display text-sm font-semibold uppercase tracking-wide">
                <ShoppingCart class="size-4 text-primary" />
                Cart
                <span v-if="cart.count" class="tabular font-mono text-muted-foreground"> ({{ cart.count }}) </span>
            </h2>
            <button
                v-if="!cart.isEmpty"
                type="button"
                class="press rounded-md px-2 py-1 text-xs text-muted-foreground transition-colors hover:text-destructive"
                @click="cart.clear()"
            >
                Clear
            </button>
        </header>

        <div v-if="cart.isEmpty" class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center">
            <ShoppingCart class="size-8 text-muted-foreground/30" />
            <p class="text-sm text-muted-foreground">Tap a product or scan a barcode.</p>
        </div>

        <!-- Fast, un-staggered insert: the cashier needs to see the line land,
             not watch it choreograph. -->
        <TransitionGroup
            v-else
            tag="ul"
            class="min-h-0 flex-1 divide-y divide-border overflow-y-auto"
            enter-from-class="opacity-0 -translate-y-1"
            enter-active-class="transition duration-[120ms] ease-out-quint"
            leave-to-class="opacity-0 translate-x-2"
            leave-active-class="transition duration-[120ms] ease-out-quint absolute"
        >
            <li v-for="line in cart.lines" :key="line.productId" class="flex items-start gap-2 p-3">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium leading-snug">{{ line.name }}</p>
                    <p class="tabular font-mono text-xs text-muted-foreground">
                        {{ formatMoney(line.unitPrice, currency) }}
                        <span v-if="line.discount > 0" class="text-primary"> − {{ formatMoney(line.discount, currency) }} </span>
                    </p>
                    <p v-if="line.trackStock && line.qty > line.stockHint" class="mt-0.5 text-[0.7rem] font-medium text-destructive">
                        Over available stock — the sale still goes through.
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    <button
                        type="button"
                        class="press flex size-8 items-center justify-center rounded-md border border-border"
                        :aria-label="`Fewer ${line.name}`"
                        @click="cart.setQty(line.productId, line.qty - 1)"
                    >
                        <Minus class="size-3.5" />
                    </button>
                    <span class="tabular w-8 text-center font-mono text-sm font-semibold">{{ line.qty }}</span>
                    <button
                        type="button"
                        class="flex size-8 items-center justify-center rounded-md border border-border"
                        :class="line.trackStock && line.qty >= line.stockHint ? 'cursor-not-allowed opacity-40' : 'press'"
                        :disabled="line.trackStock && line.qty >= line.stockHint"
                        :aria-label="`More ${line.name}`"
                        @click="cart.setQty(line.productId, line.qty + 1)"
                    >
                        <Plus class="size-3.5" />
                    </button>
                </div>

                <div class="w-20 shrink-0 text-right">
                    <p class="tabular font-mono text-sm font-semibold">
                        {{ formatMoney(cart.totals.lineSubtotals[cart.lines.indexOf(line)] ?? 0, currency) }}
                    </p>
                    <button
                        type="button"
                        class="press mt-1 text-muted-foreground transition-colors hover:text-destructive"
                        :aria-label="`Remove ${line.name}`"
                        @click="cart.remove(line.productId)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </div>
            </li>
        </TransitionGroup>
    </div>
</template>
