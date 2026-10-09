<script setup lang="ts">
import type { CurrencyDef } from '@/composables/useCurrency';
import { http } from '@/Pos/lib/http';
import { formatMoney, toDecimalString } from '@/Pos/lib/money';
import type { PosQrSettings, QrCharge } from '@/Pos/types';
import { CircleCheck, LoaderCircle, RefreshCw, WifiOff } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/*
 * The QR half of the payment modal.
 *
 * Online with a verifying provider (Bakong): ask the server for a QR with the
 * amount inside, poll until the bank says it is paid, then confirm the sale
 * on its own — the cashier does not touch anything.
 *
 * Offline, or with the static provider: show the shop's fixed QR from the
 * cached feed and let the cashier confirm after seeing the money arrive on
 * the shop phone. A dynamic charge that loses the connection mid-way keeps
 * its reference, so the server can still match the payment once it syncs.
 */
const props = defineProps<{
    total: number;
    currency: CurrencyDef;
    qr: PosQrSettings | undefined;
    online: boolean;
    storeId: number;
    registerId: number | null;
    busy?: boolean;
}>();

const emit = defineEmits<{
    paid: [reference: string | null];
}>();

/*
 * Bakong's free tier allows 100 checks a day for the whole shop. Nobody
 * scans and pays in under ~10s, so the first look waits that long, and
 * after that every 8s — about four checks for a typical sale. The cashier
 * can always ask sooner with "Check now".
 */
const FIRST_POLL_MS = 10000;
const POLL_MS = 8000;

const charge = ref<QrCharge | null>(null);
const creating = ref(false);
const failure = ref<string | null>(null);
const notice = ref<string | null>(null);
const lostConnection = ref(false);
const staticRef = ref('');
const now = ref(Date.now());
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let clock: ReturnType<typeof setInterval> | null = null;
let done = false;

const dynamic = computed(() => Boolean(props.qr?.dynamic) && props.online && !failure.value);

/** Negative once expired — a plain function, since Date.now() is not reactive. */
function rawSecondsLeft(): number {
    return charge.value?.expires_at ? Math.round((new Date(charge.value.expires_at).getTime() - Date.now()) / 1000) : 0;
}
const secondsLeft = computed(() => {
    if (!charge.value?.expires_at) return null;
    return Math.max(0, Math.round((new Date(charge.value.expires_at).getTime() - now.value) / 1000));
});
const expired = computed(() => charge.value?.status === 'expired' || secondsLeft.value === 0);
const countdown = computed(() => {
    const s = secondsLeft.value ?? 0;
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
});

async function createCharge() {
    stopPolling();
    creating.value = true;
    failure.value = null;
    notice.value = null;
    lostConnection.value = false;

    try {
        const { data } = await http.post<QrCharge>('/qr/charges', {
            amount: toDecimalString(props.total, props.currency.decimals),
            store_id: props.storeId,
            register_id: props.registerId,
        });
        charge.value = data;
        schedulePoll(FIRST_POLL_MS);
    } catch (e: any) {
        // Fall back to the static QR rather than stranding the customer.
        failure.value = e?.response?.data?.message ?? 'Could not create a QR — showing the shop QR instead.';
    } finally {
        creating.value = false;
    }
}

function schedulePoll(delay = POLL_MS) {
    stopPolling();
    pollTimer = setTimeout(poll, delay);
}

const checking = ref(false);

async function poll() {
    if (!charge.value || done) return;
    stopPolling();
    checking.value = true;

    try {
        const { data } = await http.get<QrCharge>(`/qr/charges/${charge.value.id}`);
        charge.value = { ...charge.value, ...data };
        notice.value = data.notice ?? null;
        lostConnection.value = false;

        if (data.settled) {
            finish(charge.value.reference);
            return;
        }

        if (data.status === 'failed') return;
    } catch {
        lostConnection.value = true;
    } finally {
        checking.value = false;
    }

    // Keep asking a little past expiry: a customer mid-payment as the timer
    // runs out still lands, and the server has the final word anyway.
    if (rawSecondsLeft() > -30) schedulePoll();
}

async function confirmByHand() {
    if (!charge.value) return;

    // Offline there is no one to tell; the reference still rides with the
    // sale, and the reconcile sweep checks it with the bank later.
    if (lostConnection.value) {
        finish(charge.value.reference);
        return;
    }

    try {
        const { data } = await http.post<QrCharge>(`/qr/charges/${charge.value.id}/confirm`);
        charge.value = { ...charge.value, ...data };
        finish(charge.value.reference);
    } catch (e: any) {
        notice.value = e?.response?.data?.message ?? 'Could not confirm — try again.';
    }
}

function finish(reference: string | null) {
    if (done) return;
    done = true;
    stopPolling();
    emit('paid', reference);
}

function stopPolling() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
}

onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 1000);
    if (dynamic.value) void createCharge();
});

onBeforeUnmount(() => {
    stopPolling();
    if (clock) clearInterval(clock);

    // Switched method or closed the modal: tell the server so the QR is not
    // left looking payable. Best effort — reconcile covers a lost request.
    if (!done && charge.value && charge.value.status === 'pending') {
        void http.post(`/qr/charges/${charge.value.id}/cancel`).catch(() => undefined);
    }
});
</script>

<template>
    <div class="mt-4">
        <!-- Per-sale QR -->
        <template v-if="dynamic">
            <div v-if="creating || !charge" class="flex h-64 items-center justify-center">
                <LoaderCircle class="size-6 animate-spin text-muted-foreground" />
            </div>

            <template v-else>
                <div class="relative mx-auto size-60">
                    <div
                        class="size-full overflow-hidden rounded-xl border border-border bg-white p-2 transition-opacity [&>svg]:size-full"
                        :class="expired ? 'opacity-15' : ''"
                        v-html="charge.svg"
                    />
                    <button
                        v-if="expired"
                        type="button"
                        class="press absolute inset-0 m-auto flex h-11 w-40 items-center justify-center gap-2 rounded-lg bg-primary text-sm font-semibold text-primary-foreground"
                        @click="createCharge"
                    >
                        <RefreshCw class="size-4" /> New QR
                    </button>
                </div>

                <p class="mt-2 text-center text-sm font-medium">{{ qr?.merchant_name }}</p>

                <div class="mt-2 flex items-center justify-center gap-2 text-xs" aria-live="polite">
                    <template v-if="charge.status === 'failed'">
                        <span class="text-destructive">{{ notice ?? 'The bank flagged this payment. Check the shop account.' }}</span>
                    </template>
                    <template v-else-if="lostConnection">
                        <WifiOff class="size-3.5 text-destructive" />
                        <span class="text-destructive">Connection lost — check the shop phone for the payment.</span>
                    </template>
                    <template v-else-if="!expired">
                        <span class="relative flex size-2">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-primary opacity-60" />
                            <span class="relative inline-flex size-2 rounded-full bg-primary" />
                        </span>
                        <span class="text-muted-foreground"
                            >Waiting for payment · <span class="tabular font-mono">{{ countdown }}</span></span
                        >
                    </template>
                    <template v-else>
                        <span class="text-muted-foreground">QR expired.</span>
                    </template>
                </div>

                <p v-if="notice && charge.status !== 'failed'" class="mt-1 text-center text-xs text-muted-foreground">{{ notice }}</p>

                <button
                    v-if="!expired && charge.status === 'pending'"
                    type="button"
                    :disabled="checking"
                    class="press mx-auto mt-3 flex h-9 items-center gap-1.5 rounded-lg px-3 text-xs font-medium text-primary disabled:opacity-40"
                    @click="poll"
                >
                    <RefreshCw class="size-3.5" :class="checking ? 'animate-spin' : ''" />
                    Check now
                </button>

                <button
                    v-if="qr?.manual_confirm"
                    type="button"
                    :disabled="busy"
                    class="press mt-4 flex h-11 w-full items-center justify-center gap-2 rounded-lg border border-border text-sm font-medium text-muted-foreground disabled:opacity-40"
                    @click="confirmByHand"
                >
                    <CircleCheck class="size-4" />
                    Payment arrived — confirm by hand
                </button>
            </template>
        </template>

        <!-- Static shop QR: offline, or a shop without a verifying provider -->
        <template v-else>
            <p v-if="failure" class="mb-3 rounded-lg bg-destructive/10 px-3 py-2 text-xs text-destructive">{{ failure }}</p>
            <p v-else-if="qr?.dynamic && !online" class="mb-3 flex items-center gap-2 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                <WifiOff class="size-3.5 shrink-0" /> Offline — showing the shop QR. The customer types the amount.
            </p>

            <template v-if="qr?.static_svg">
                <div class="mx-auto size-60 overflow-hidden rounded-xl border border-border bg-white p-2 [&>svg]:size-full" v-html="qr.static_svg" />
                <p class="mt-2 text-center text-sm">
                    Customer pays <strong class="tabular font-mono text-primary">{{ formatMoney(total, currency) }}</strong> to {{ qr.merchant_name }}
                </p>
            </template>
            <p v-else class="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                No shop QR set up yet. An admin can add the Bakong account in Settings → Payments.
            </p>

            <label for="qr-ref" class="mt-4 block text-xs font-medium text-muted-foreground">Transaction no. (optional)</label>
            <input
                id="qr-ref"
                v-model="staticRef"
                type="text"
                placeholder="From the payment notification"
                class="mt-1 h-11 w-full rounded-lg border border-input bg-background px-3 font-mono text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />

            <button
                type="button"
                :disabled="busy"
                class="press mt-4 flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-primary text-base font-semibold text-primary-foreground disabled:opacity-40"
                @click="finish(staticRef.trim() || null)"
            >
                <CircleCheck class="size-5" />
                {{ busy ? 'Saving…' : 'Payment received' }}
            </button>
        </template>
    </div>
</template>
