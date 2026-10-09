import type { PosCategory, PosProduct, PosRegister, PosSettings, StoredOrder } from '@/Pos/types';
import Dexie, { type Table } from 'dexie';

/**
 * Local store for the POS screen.
 *
 * Two jobs, and they have very different risk profiles:
 *
 *  - **products / meta** is a cache. Losing it costs a refetch, nothing more.
 *  - **orders** is a queue of completed sales that the server has not seen
 *    yet. Every row is money already in the drawer. A row here is only ever
 *    deleted once the server has confirmed it — never on a failure, never on
 *    a timeout, never to tidy up.
 */

export interface MetaRow {
    key: string;
    value: unknown;
}

class PosDatabase extends Dexie {
    products!: Table<PosProduct, number>;

    orders!: Table<StoredOrder, string>;

    meta!: Table<MetaRow, string>;

    constructor() {
        super('pos-retail');

        this.version(1).stores({
            // Indexed on barcode and sku so a scan is a lookup, not a scan.
            products: 'id, barcode, sku, category_id, name',
            orders: 'client_uuid, state, created_offline_at',
            meta: 'key',
        });
    }
}

export const db = new PosDatabase();

/* -------------------------------------------------------------------------- */
/* Catalogue cache                                                             */
/* -------------------------------------------------------------------------- */

export async function cacheFeed(feed: {
    store_id: number;
    synced_at: string;
    products: PosProduct[];
    categories: PosCategory[];
    registers: PosRegister[];
    settings: PosSettings;
}): Promise<void> {
    await db.transaction('rw', db.products, db.meta, async () => {
        await db.products.clear();
        await db.products.bulkPut(feed.products);
        await db.meta.bulkPut([
            { key: 'store_id', value: feed.store_id },
            { key: 'synced_at', value: feed.synced_at },
            { key: 'categories', value: feed.categories },
            { key: 'registers', value: feed.registers },
            { key: 'settings', value: feed.settings },
        ]);
    });
}

export async function readCachedFeed() {
    const [products, rows] = await Promise.all([db.products.orderBy('name').toArray(), db.meta.toArray()]);

    if (products.length === 0) return null;

    const meta = Object.fromEntries(rows.map((r) => [r.key, r.value])) as Record<string, never>;

    return {
        store_id: meta.store_id ?? 0,
        synced_at: meta.synced_at ?? null,
        products,
        categories: meta.categories ?? [],
        registers: meta.registers ?? [],
        settings: meta.settings ?? {
            receipt_header: 'Receipt',
            receipt_footer: null,
            currency: { code: 'USD', symbol: '$', decimals: 2, riel_per_usd: 4100 },
        },
    };
}

/* -------------------------------------------------------------------------- */
/* Order queue                                                                 */
/* -------------------------------------------------------------------------- */

/** How many synced orders to keep locally so a receipt can be reprinted. */
const RECEIPT_HISTORY = 50;

export async function queueOrder(order: StoredOrder): Promise<void> {
    await db.orders.put(order);
}

export async function pendingOrders(): Promise<StoredOrder[]> {
    return db.orders.where('state').equals('pending_sync').sortBy('created_offline_at');
}

export async function pendingCount(): Promise<number> {
    return db.orders.where('state').equals('pending_sync').count();
}

/**
 * Confirmed by the server. The row is kept — not deleted — so the cashier can
 * reprint the receipt, and the old tail is trimmed afterwards.
 */
export async function markSynced(clientUuid: string, orderNo: string | null, total: string | null): Promise<void> {
    await db.orders.update(clientUuid, {
        state: 'synced',
        order_no: orderNo,
        ...(total ? { total } : {}),
        last_error: null,
    });

    await trimHistory();
}

/**
 * Records a failed attempt. The order stays 'pending_sync' on purpose: a
 * failure is a reason to retry later, never a reason to drop a real sale.
 */
export async function markAttemptFailed(clientUuid: string, error: string): Promise<void> {
    const existing = await db.orders.get(clientUuid);
    if (!existing) return;

    await db.orders.update(clientUuid, {
        attempts: (existing.attempts ?? 0) + 1,
        last_error: error,
    });
}

/**
 * The server understood the request and refused this order outright — a
 * product or register it names no longer exists, say. Retrying cannot help,
 * so it stops being "pending": one unfixable sale must not hold the queue,
 * or the till, hostage forever.
 *
 * The row is kept, as every row here is. The sale still happened, and a human
 * has to decide what to do about it.
 */
export async function markRejected(clientUuid: string, error: string): Promise<void> {
    const existing = await db.orders.get(clientUuid);
    if (!existing) return;

    await db.orders.update(clientUuid, {
        state: 'failed',
        attempts: (existing.attempts ?? 0) + 1,
        last_error: error,
    });
}

export async function rejectedCount(): Promise<number> {
    return db.orders.where('state').equals('failed').count();
}

export async function rejectedOrders(): Promise<StoredOrder[]> {
    return db.orders.where('state').equals('failed').sortBy('created_offline_at');
}

/** Put a rejected order back in the queue, once whatever it named exists again. */
export async function requeueRejected(): Promise<number> {
    const stuck = await rejectedOrders();

    await Promise.all(stuck.map((order) => db.orders.update(order.client_uuid, { state: 'pending_sync', last_error: null })));

    return stuck.length;
}

export async function recentOrders(limit = 20): Promise<StoredOrder[]> {
    const all = await db.orders.orderBy('created_offline_at').reverse().limit(limit).toArray();
    return all;
}

export async function findOrder(clientUuid: string): Promise<StoredOrder | undefined> {
    return db.orders.get(clientUuid);
}

async function trimHistory(): Promise<void> {
    const synced = await db.orders.where('state').equals('synced').sortBy('created_offline_at');

    if (synced.length <= RECEIPT_HISTORY) return;

    const stale = synced.slice(0, synced.length - RECEIPT_HISTORY).map((o) => o.client_uuid);
    await db.orders.bulkDelete(stale);
}
