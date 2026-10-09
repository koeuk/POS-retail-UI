export interface PosProduct {
    id: number;
    name: string;
    /** Set when this row is a pack of another product — a case of the base unit. */
    parent_product_id: number | null;
    /** Base units one of these contains. 1 for a base product. */
    units_per_pack: number;
    sku: string;
    barcode: string | null;
    category_id: number;
    category_name: string | null;
    sell_price: string;
    unit: string;
    image: string | null;
    track_stock: boolean;
    /**
     * A hint for the cashier, in units of THIS row: for a case of 24 it is how
     * many whole cases the loose count covers. Stock is only ever decided
     * server-side at sync.
     */
    stock_qty: number;
}

export interface CartLine {
    productId: number;
    name: string;
    unitPrice: number;
    qty: number;
    discount: number;
    unit: string;
    trackStock: boolean;
    stockHint: number;
}

export type PaymentMethod = 'cash' | 'card' | 'qr' | 'credit';

/** Mirror of App\Enums\SaleType. */
export type SaleType = 'customer' | 'debt' | 'myself';

/** How the till takes QR — see PosDataController::qrSettings(). */
export interface PosQrSettings {
    provider: string;
    label: string;
    /** A configured provider that mints a QR per sale and confirms it itself. */
    dynamic: boolean;
    /** Whether a cashier may confirm a per-sale QR the bank has not. */
    manual_confirm: boolean;
    merchant_name: string;
    /** The shop's fixed KHQR, pre-rendered — what is shown offline. */
    static_svg: string | null;
}

/** A per-sale QR from POST /pos/data/qr/charges. */
export interface QrCharge {
    id: string;
    provider: string;
    reference: string;
    amount: string;
    currency: string;
    status: 'pending' | 'paid' | 'manual' | 'expired' | 'failed' | 'cancelled';
    settled: boolean;
    expires_at?: string;
    svg?: string;
    notice?: string | null;
}

export interface PosSettings {
    receipt_header: string;
    receipt_footer: string | null;
    currency: { code: string; symbol: string; decimals: number; riel_per_usd: number };
    /** Absent in a feed cached before QR payments existed. */
    qr?: PosQrSettings;
}

export interface PosRegister {
    id: number;
    name: string;
}

export interface PosCategory {
    id: number;
    name: string;
}

export interface PosFeed {
    store_id: number;
    synced_at: string;
    products: PosProduct[];
    categories: PosCategory[];
    registers: PosRegister[];
    settings: PosSettings;
}

/** Exactly the shape POST /pos/data/orders/sync expects. */
export interface QueuedOrder {
    client_uuid: string;
    store_id: number;
    register_id: number | null;
    customer_id: number | null;
    sale_type: SaleType;
    created_offline_at: string;
    discount_amount: string;
    items: {
        product_id: number;
        product_name: string;
        qty: number;
        unit_price: string;
        discount: string;
    }[];
    payments: {
        method: PaymentMethod;
        amount: string;
        reference_no: string | null;
    }[];
}

export type SyncState = 'pending_sync' | 'synced' | 'failed';

export interface StoredOrder extends QueuedOrder {
    state: SyncState;
    /** Server figures, filled in once the order syncs. */
    order_no: string | null;
    total: string;
    attempts: number;
    last_error: string | null;
    receipt: {
        subtotal: string;
        total: string;
        paid: string;
        change: string;
        cashier: string;
        store: string;
    };
}
