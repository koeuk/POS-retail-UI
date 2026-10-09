import { markAttemptFailed, markRejected, markSynced, pendingCount, pendingOrders, rejectedCount, requeueRejected } from '@/Pos/db/dexie';
import { SessionExpired, http, isReallyOnline } from '@/Pos/lib/http';
import type { StoredOrder } from '@/Pos/types';
import type { AxiosError } from 'axios';
import { onBeforeUnmount, onMounted, readonly, ref } from 'vue';

/** How often to retry the queue while the app is open. */
const SYNC_INTERVAL_MS = 15_000;

/** Server caps a flush at 200; stay under it. */
const BATCH_SIZE = 100;

interface SyncResult {
    client_uuid: string;
    status: 'created' | 'already_synced' | 'failed';
    order_no: string | null;
    total: string | null;
    message: string | null;
}

/**
 * Drains the offline order queue.
 *
 * The invariant that matters more than anything else here: **a queued order is
 * only ever removed from "pending" when the server has confirmed it.** Not on
 * a timeout, not on a 500, not on an expired session. Every one of those rows
 * is a sale that already happened, and dropping one loses real money.
 */
export function useOfflineSync() {
    const online = ref(navigator.onLine);
    const syncing = ref(false);
    const pending = ref(0);
    const authExpired = ref(false);
    const lastError = ref<string | null>(null);
    const lastSyncedAt = ref<string | null>(null);
    const rejected = ref(0);

    let timer: ReturnType<typeof setInterval> | null = null;

    async function refreshCount() {
        pending.value = await pendingCount();
        rejected.value = await rejectedCount();
    }

    /**
     * Laravel reports batch validation failures keyed by position —
     * `orders.3.items.0.product_id`. Pull out which orders in the batch the
     * server refused, so the rest of the queue is not held up by them.
     */
    function refusedIndices(errors: Record<string, string[]>): Map<number, string> {
        const refused = new Map<number, string>();

        for (const [key, messages] of Object.entries(errors ?? {})) {
            const match = /^orders\.(\d+)\b/.exec(key);
            if (!match) continue;

            const index = Number(match[1]);
            if (!refused.has(index)) refused.set(index, messages?.[0] ?? 'The server refused this order.');
        }

        return refused;
    }

    /**
     * A 422 means the server read the request and rejected specific orders —
     * a product or register they name has been deleted, most likely. Retrying
     * an unchanged payload will fail identically forever, so those orders are
     * set aside rather than left to block everything behind them.
     *
     * Returns true when the whole batch was accounted for.
     */
    async function setAsideRefused(batch: StoredOrder[], errors: Record<string, string[]>): Promise<boolean> {
        const refused = refusedIndices(errors);

        if (refused.size === 0) return false;

        for (const [index, message] of refused) {
            const order = batch[index];
            if (order) await markRejected(order.client_uuid, message);
        }

        return true;
    }

    /**
     * navigator.onLine only knows whether a network interface is up. On café
     * wifi behind a captive portal it happily reports "online" while every
     * request fails, so the flag is confirmed against the server.
     */
    async function checkConnection(): Promise<boolean> {
        const reachable = await isReallyOnline();
        online.value = reachable;
        return reachable;
    }

    async function flush(): Promise<void> {
        if (syncing.value) return;

        await refreshCount();
        if (pending.value === 0) return;

        if (!(await checkConnection())) return;

        syncing.value = true;

        // The server keys its validation errors by position within the batch it
        // was sent, so the catch below has to know which batch that was.
        let currentBatch: StoredOrder[] = [];

        try {
            const queued = await pendingOrders();

            for (let i = 0; i < queued.length; i += BATCH_SIZE) {
                const batch = queued.slice(i, i + BATCH_SIZE);
                currentBatch = batch;

                const { data } = await http.post<{ results: SyncResult[] }>('/orders/sync', {
                    // Send only the wire fields. The local bookkeeping —
                    // state, attempts, last_error, the printed receipt — never
                    // leaves the device.
                    orders: batch.map((o) => ({
                        client_uuid: o.client_uuid,
                        store_id: o.store_id,
                        register_id: o.register_id,
                        customer_id: o.customer_id,
                        // Easy to forget: this allowlist is the ONLY path to
                        // the server, so a field left out here is silently
                        // dropped and the server falls back to a default.
                        // That is exactly how a "myself" sale once landed as
                        // a paid customer sale.
                        sale_type: o.sale_type,
                        created_offline_at: o.created_offline_at,
                        discount_amount: o.discount_amount,
                        items: o.items,
                        payments: o.payments,
                    })),
                });

                for (const result of data.results ?? []) {
                    if (result.status === 'failed') {
                        // Stays pending. A failure is a reason to retry, not
                        // a reason to lose the sale.
                        await markAttemptFailed(result.client_uuid, result.message ?? 'Server rejected the order.');
                        continue;
                    }

                    // 'created' and 'already_synced' are both confirmation
                    // that the server holds this order exactly once.
                    await markSynced(result.client_uuid, result.order_no, result.total);
                }
            }

            authExpired.value = false;
            lastError.value = null;
            lastSyncedAt.value = new Date().toISOString();
        } catch (error) {
            if (error instanceof SessionExpired) {
                // Only a human can fix this. The queue is untouched and the
                // cashier can keep selling in the meantime.
                authExpired.value = true;
                lastError.value = 'Session expired — sign in again to sync.';
            } else {
                const response = (error as AxiosError)?.response;

                /*
                 * A reply — any reply — proves the server is reachable.
                 * Reporting a rejected payload as "offline" sends the cashier
                 * to check the wifi over something the network had no part in,
                 * and it holds the navigation lock closed indefinitely.
                 */
                if (response) {
                    online.value = true;

                    const errors = (response.data as { errors?: Record<string, string[]> })?.errors ?? {};
                    const handled = response.status === 422 && (await setAsideRefused(currentBatch, errors));

                    lastError.value = handled
                        ? 'Some sales were refused by the server and need attention.'
                        : `The server refused the sync (${response.status}).`;
                } else {
                    online.value = false;
                    lastError.value = 'Could not reach the server.';
                }
            }
        } finally {
            syncing.value = false;
            await refreshCount();
        }
    }

    function handleOnline() {
        online.value = true;
        void flush();
    }

    function handleOffline() {
        online.value = false;
    }

    onMounted(async () => {
        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);

        // Belt and braces: the 'online' event fires on reconnection, the
        // interval covers the case where it never fires or the first attempt
        // failed for an unrelated reason.
        timer = setInterval(() => void flush(), SYNC_INTERVAL_MS);

        await refreshCount();
        await checkConnection();
        void flush();
    });

    onBeforeUnmount(() => {
        window.removeEventListener('online', handleOnline);
        window.removeEventListener('offline', handleOffline);
        if (timer) clearInterval(timer);
    });

    /** Try the set-aside orders once more — after the missing data is restored. */
    async function retryRejected(): Promise<void> {
        await requeueRejected();
        await refreshCount();
        await flush();
    }

    return {
        online: readonly(online),
        syncing: readonly(syncing),
        pending: readonly(pending),
        rejected: readonly(rejected),
        authExpired: readonly(authExpired),
        lastError: readonly(lastError),
        lastSyncedAt: readonly(lastSyncedAt),
        flush,
        retryRejected,
        refreshCount,
        checkConnection,
    };
}
