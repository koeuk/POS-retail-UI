<script setup lang="ts">
import type { CurrencyDef } from '@/composables/useCurrency';
import { formatMoney } from '@/Pos/lib/money';
import type { PaymentMethod, PosQrSettings } from '@/Pos/types';
import { Banknote, Check, CreditCard, Delete, QrCode, Wallet, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import QrPayPanel from './QrPayPanel.vue';

const props = defineProps<{
    open: boolean;
    total: number;
    currency: CurrencyDef;
    busy?: boolean;
    qr?: PosQrSettings;
    online: boolean;
    storeId: number;
    registerId: number | null;
}>();

const emit = defineEmits<{
    close: [];
    confirm: [payment: { method: PaymentMethod; amount: number; reference: string | null }];
}>();

const methods: { value: PaymentMethod; label: string; icon: typeof Banknote }[] = [
    { value: 'cash', label: 'Cash', icon: Banknote },
    { value: 'card', label: 'Card', icon: CreditCard },
    { value: 'qr', label: 'QR', icon: QrCode },
    { value: 'credit', label: 'Credit', icon: Wallet },
];

const method = ref<PaymentMethod>('cash');
const tendered = ref('');
const reference = ref('');

/* Card, QR and credit are always exact — only cash is keyed in and only cash
   gives change, which is why the numpad appears for cash alone. */
const isCash = computed(() => method.value === 'cash');
/* QR confirms itself — from the bank, or the cashier's tap inside the panel. */
const isQr = computed(() => method.value === 'qr');

const amount = computed(() => (isCash.value ? Number(tendered.value) || 0 : props.total));
const change = computed(() => (isCash.value ? Math.max(0, amount.value - props.total) : 0));
const short = computed(() => isCash.value && amount.value > 0 && amount.value < props.total);
const canConfirm = computed(() => !props.busy && (!isCash.value || amount.value >= props.total));

/** Round up to the next note a customer is likely to hand over. */
const quickCash = computed(() => {
    const t = props.total;
    const steps = [1, 5, 10, 20, 50, 100];
    const out = new Set<number>([Math.ceil(t * 100) / 100]);

    for (const step of steps) {
        const up = Math.ceil(t / step) * step;
        if (up >= t) out.add(up);
    }

    return [...out].sort((a, b) => a - b).slice(0, 4);
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        method.value = 'cash';
        tendered.value = '';
        reference.value = '';
    },
);

function press(key: string) {
    if (key === 'del') {
        tendered.value = tendered.value.slice(0, -1);
        return;
    }
    if (key === '.' && tendered.value.includes('.')) return;

    // Never accept more than two decimal places — money has two.
    const next = tendered.value + key;
    if (/\.\d{3,}$/.test(next)) return;

    tendered.value = next;
}

function qrPaid(reference: string | null) {
    emit('confirm', { method: 'qr', amount: props.total, reference });
}

function confirm() {
    if (!canConfirm.value) return;

    emit('confirm', {
        method: method.value,
        amount: amount.value,
        reference: reference.value.trim() || null,
    });
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-from-class="opacity-0"
            enter-active-class="transition-opacity duration-150"
            leave-to-class="opacity-0"
            leave-active-class="transition-opacity duration-150"
        >
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 sm:items-center">
                <Transition
                    appear
                    enter-from-class="opacity-0 translate-y-4 sm:scale-95 sm:translate-y-0"
                    enter-active-class="transition duration-200 ease-out-quint"
                >
                    <div
                        class="flex max-h-[92dvh] w-full max-w-md flex-col overflow-hidden rounded-t-2xl border border-border bg-card shadow-2xl sm:rounded-2xl"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Take payment"
                    >
                        <!-- Amount due -->
                        <div class="shrink-0 border-b border-border p-5 text-center">
                            <div class="flex items-start justify-between">
                                <p class="font-mono text-[0.65rem] uppercase tracking-[0.18em] text-muted-foreground">Amount due</p>
                                <button
                                    type="button"
                                    class="press -mt-1 rounded-md p-1 text-muted-foreground"
                                    aria-label="Cancel"
                                    @click="emit('close')"
                                >
                                    <X class="size-4" />
                                </button>
                            </div>
                            <p class="tabular mt-1 font-mono text-4xl font-bold text-primary">
                                {{ formatMoney(total, currency) }}
                            </p>
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto p-4">
                            <!-- Method -->
                            <div class="grid grid-cols-4 gap-1.5">
                                <button
                                    v-for="m in methods"
                                    :key="m.value"
                                    type="button"
                                    class="press flex h-16 flex-col items-center justify-center gap-1 rounded-lg border text-xs font-medium"
                                    :class="
                                        method === m.value
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-border text-muted-foreground'
                                    "
                                    @click="method = m.value"
                                >
                                    <component :is="m.icon" class="size-5" />
                                    {{ m.label }}
                                </button>
                            </div>

                            <!-- Cash: tendered + numpad -->
                            <template v-if="isCash">
                                <div class="mt-4 rounded-lg border border-border p-3">
                                    <div class="flex items-baseline justify-between">
                                        <span class="text-xs text-muted-foreground">Tendered</span>
                                        <span class="tabular font-mono text-2xl font-semibold">
                                            {{ tendered || '0.00' }}
                                        </span>
                                    </div>
                                    <div
                                        class="mt-1 flex items-baseline justify-between border-t border-border pt-1"
                                        :class="short ? 'text-destructive' : 'text-primary'"
                                    >
                                        <span class="text-xs font-medium">{{ short ? 'Still owing' : 'Change' }}</span>
                                        <span class="tabular font-mono text-lg font-semibold">
                                            {{ formatMoney(short ? total - amount : change, currency) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="mt-3 grid grid-cols-4 gap-1.5">
                                    <button
                                        v-for="q in quickCash"
                                        :key="q"
                                        type="button"
                                        class="press tabular h-10 rounded-lg border border-border font-mono text-sm"
                                        @click="tendered = q.toFixed(2)"
                                    >
                                        {{ q.toFixed(2) }}
                                    </button>
                                </div>

                                <div class="mt-2 grid grid-cols-3 gap-1.5">
                                    <button
                                        v-for="key in ['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0']"
                                        :key="key"
                                        type="button"
                                        class="press h-14 rounded-lg border border-border font-mono text-xl font-medium"
                                        @click="press(key)"
                                    >
                                        {{ key }}
                                    </button>
                                    <button
                                        type="button"
                                        class="press flex h-14 items-center justify-center rounded-lg border border-border"
                                        aria-label="Backspace"
                                        @click="press('del')"
                                    >
                                        <Delete class="size-5" />
                                    </button>
                                </div>
                            </template>

                            <QrPayPanel
                                v-else-if="isQr"
                                :total="total"
                                :currency="currency"
                                :qr="qr"
                                :online="online"
                                :store-id="storeId"
                                :register-id="registerId"
                                :busy="busy"
                                @paid="qrPaid"
                            />

                            <!-- Card, credit: a reference to reconcile against -->
                            <div v-else class="mt-4">
                                <label for="pay-ref" class="text-xs font-medium text-muted-foreground"> Reference (optional) </label>
                                <input
                                    id="pay-ref"
                                    v-model="reference"
                                    type="text"
                                    placeholder="Terminal or transaction no."
                                    class="mt-1 h-12 w-full rounded-lg border border-input bg-background px-3 font-mono text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                                <p class="mt-2 text-xs text-muted-foreground">{{ formatMoney(total, currency) }} will be recorded as paid in full.</p>
                            </div>
                        </div>

                        <div v-if="!isQr" class="shrink-0 border-t border-border p-4">
                            <button
                                type="button"
                                :disabled="!canConfirm"
                                class="press flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-primary text-base font-semibold text-primary-foreground disabled:opacity-40"
                                @click="confirm"
                            >
                                <Check class="size-5" />
                                {{ busy ? 'Saving…' : 'Confirm payment' }}
                            </button>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
