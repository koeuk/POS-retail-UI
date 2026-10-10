import { http } from '@/Pos/lib/http';
import type { PosFeed, PosProduct } from '@/Pos/types';
import {
    mockCategories,
    mockCustomers,
    mockOrders,
    mockProducts,
    mockSharedData,
} from './data';

/**
 * Initializes in-browser API mock interceptors for Axios and native Fetch.
 * This ensures the offline POS till, barcode scanner, debt lookup, and checkout
 * work completely without needing any backend server.
 */
export function setupMockApi() {
    // 1. Intercept Axios (used by Pos module)
    http.interceptors.request.use(async (config) => {
        const url = config.url || '';

        // Simulated network delay for realistic responsiveness (50–200ms)
        const delay = 50 + Math.random() * 150;
        await new Promise((r) => setTimeout(r, delay));

        // /heartbeat
        if (url.includes('/heartbeat')) {
            config.adapter = async () => ({
                data: { status: 'ok', online: true },
                status: 200,
                statusText: 'OK',
                headers: {},
                config,
            });
            return config;
        }

        // /products
        if (url.includes('/products')) {
            const posProducts: PosProduct[] = mockProducts.map((p) => ({
                id: p.id,
                name: p.name,
                parent_product_id: p.parent_product_id ?? null,
                units_per_pack: p.units_per_pack ?? 1,
                sku: p.sku,
                barcode: p.barcode,
                category_id: p.category_id,
                category_name: p.category?.name ?? 'General',
                sell_price: p.sell_price,
                unit: p.unit,
                image: p.image,
                track_stock: p.track_stock,
                stock_qty: p.stock_qty ?? 50,
            }));

            const feed: PosFeed = {
                store_id: 1,
                synced_at: new Date().toISOString(),
                products: posProducts,
                categories: mockCategories.map((c) => ({ id: c.id, name: c.name })),
                registers: [
                    { id: 1, name: 'Till 01 (Front)' },
                    { id: 2, name: 'Till 02 (Express)' },
                ],
                settings: {
                    receipt_header: 'Smile Mart Cambodia - BKK1 Branch\nThank you for shopping with us!',
                    receipt_footer: 'Tel: +855 23 991 234\nExchange within 3 days with receipt.',
                    currency: mockSharedData.currency,
                    qr: {
                        provider: 'bakong',
                        label: 'Bakong KHQR',
                        dynamic: false,
                        manual_confirm: true,
                        merchant_name: 'SMILE MART CAMBODIA',
                        static_svg: null,
                    },
                },
            };

            config.adapter = async () => ({
                data: feed,
                status: 200,
                statusText: 'OK',
                headers: {},
                config,
            });
            return config;
        }

        // /customers
        if (url.includes('/customers')) {
            config.adapter = async () => ({
                data: mockCustomers.map((c) => ({ id: c.id, name: c.name, phone: c.phone })),
                status: 200,
                statusText: 'OK',
                headers: {},
                config,
            });
            return config;
        }

        // /orders/sync
        if (url.includes('/orders/sync')) {
            const body = typeof config.data === 'string' ? JSON.parse(config.data) : config.data;
            const orders = body?.orders || [];
            
            // 10% chance of simulating sync failure for testing error handling
            const shouldFail = Math.random() < 0.1;
            
            if (shouldFail) {
                config.adapter = async () => ({
                    data: { error: 'Sync failed', message: 'Unable to reach server' },
                    status: 500,
                    statusText: 'Internal Server Error',
                    headers: {},
                    config,
                });
                return config;
            }
            
            const results = orders.map((o: any, idx: number) => {
                const orderNo = `ORD-${new Date().getFullYear()}-${String(mockOrders.length + idx + 1).padStart(4, '0')}`;
                return {
                    client_uuid: o.client_uuid,
                    status: 'created',
                    order_no: orderNo,
                    total: o.items?.reduce((sum: number, it: any) => sum + Number(it.unit_price) * it.qty, 0).toFixed(2),
                    message: 'Order synced successfully',
                };
            });

            config.adapter = async () => ({
                data: { results },
                status: 200,
                statusText: 'OK',
                headers: {},
                config,
            });
            return config;
        }

        // Fallback for other pos endpoints
        config.adapter = async () => ({
            data: { success: true },
            status: 200,
            statusText: 'OK',
            headers: {},
            config,
        });

        return config;
    });

    // 2. Intercept window.fetch (used by Debts/Index.vue)
    const originalFetch = window.fetch.bind(window);
    window.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
        const urlStr = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;

        if (urlStr.includes('debts/product-lookup') || urlStr.includes('debts.products')) {
            const parsed = new URL(urlStr, window.location.origin);
            const q = (parsed.searchParams.get('q') || '').toLowerCase();
            const matched = mockProducts
                .filter((p) => p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q) || (p.barcode && p.barcode.includes(q)))
                .slice(0, 10)
                .map((p) => ({
                    id: p.id,
                    name: p.name,
                    sku: p.sku,
                    barcode: p.barcode,
                    sell_price: p.sell_price,
                    unit: p.unit,
                }));

            return new Response(JSON.stringify(matched), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        }

        if (urlStr.includes('pos/data/customers') || urlStr.includes('pos.data.customers')) {
            const parsed = new URL(urlStr, window.location.origin);
            const q = (parsed.searchParams.get('q') || '').toLowerCase();
            const matched = mockCustomers
                .filter((c) => c.name.toLowerCase().includes(q) || (c.phone && c.phone.includes(q)))
                .slice(0, 10)
                .map((c) => ({ id: c.id, name: c.name, phone: c.phone }));

            return new Response(JSON.stringify(matched), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        }

        return originalFetch(input, init);
    };
}
