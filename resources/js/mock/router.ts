import type { DefineComponent } from 'vue';
import {
    computed,
    defineComponent,
    h,
    markRaw,
    onMounted,
    reactive,
    ref,
    shallowRef,
    watch,
} from 'vue';
import {
    mockCategories,
    mockCustomers,
    mockDashboardData,
    mockDebts,
    mockOrders,
    mockProducts,
    mockSharedData,
    mockStocks,
    mockStores,
    mockUsers,
    paginate,
} from './data';

/* -------------------------------------------------------------------------- */
/* Reactive Router & Page State                                               */
/* -------------------------------------------------------------------------- */

const currentPath = ref(window.location.pathname === '/' ? '/dashboard' : window.location.pathname);
const currentSearch = ref(window.location.search);
const currentComponentName = ref('Dashboard');
const currentComponent = shallowRef<any>(null);
const currentPageProps = reactive<Record<string, any>>({});
let registeredPages: Record<string, () => Promise<{ default: DefineComponent }>> = {};
let appTitleFormatter: ((title: string) => string) | null = null;

/* -------------------------------------------------------------------------- */
/* route() Helper Function                                                    */
/* -------------------------------------------------------------------------- */

const routeMap: Record<string, string> = {
    'home': '/',
    'dashboard': '/dashboard',
    'pos.index': '/pos',
    'products.index': '/products',
    'products.create': '/products/create',
    'products.show': '/products/{product}',
    'products.edit': '/products/{product}/edit',
    'products.store': '/products',
    'products.update': '/products/{product}',
    'products.destroy': '/products/{product}',
    'orders.index': '/orders',
    'orders.show': '/orders/{order}',
    'debts.index': '/debts',
    'debts.products': '/debts/product-lookup',
    'debts.settle': '/debts/{debt}/settle',
    'inventory.index': '/inventory',
    'categories.index': '/categories',
    'categories.store': '/categories',
    'categories.update': '/categories/{category}',
    'categories.destroy': '/categories/{category}',
    'categories.history': '/categories/{subjectId}/history',
    'products.history': '/products/{subjectId}/history',
    'customers.history': '/customers/{subjectId}/history',
    'stores.history': '/stores/{subjectId}/history',
    'inventory.history': '/inventory/{subjectId}/history',
    'users.history': '/users/{subjectId}/history',
    'customers.index': '/customers',
    'customers.store': '/customers',
    'customers.update': '/customers/{customer}',
    'customers.destroy': '/customers/{customer}',
    'stores.index': '/stores',
    'stores.store': '/stores',
    'stores.update': '/stores/{store}',
    'stores.destroy': '/stores/{store}',
    'users.index': '/users',
    'users.store': '/users',
    'users.update': '/users/{user}',
    'users.destroy': '/users/{user}',
    'reports.index': '/reports',
    'myself.index': '/myself',
    'consumption.index': '/myself',
    'menu.index': '/menu',
    'menu.show': '/menu/{product}',
    'activity.index': '/activity',
    'activity.show': '/activity/{activity}',
    'settings.shop': '/settings/shop',
    'settings.profile': '/settings/profile',
    'profile.edit': '/settings/profile',
    'profile.destroy': '/settings/profile',
    'settings.password': '/settings/password',
    'settings.payments': '/settings/payments',
    'settings.appearance': '/settings/appearance',
    'logout': '/logout',
    'login': '/login',
    'password.request': '/forgot-password',
    'password.email': '/password/email',
    'password.store': '/password/reset',
    'password.reset': '/password/reset/{token}',
    'password.confirm': '/user/confirm-password',
    'password.otp.verify': '/password/otp/verify',
    'password.otp.resend': '/password/otp/resend',
    'verification.send': '/email/verification-notification',
    'verification.notice': '/verify-email',
};

export function route(name: string, params?: any): string {
    let path = routeMap[name];
    if (!path) {
        console.warn(`Route "${name}" not found, returning /dashboard`);
        return '/dashboard';
    }

    if (params !== undefined && params !== null) {
        if (typeof params === 'object' && !Array.isArray(params)) {
            Object.entries(params).forEach(([key, value]) => {
                path = path.replace(`{${key}}`, String(value));
            });
            // If any placeholder remains and a single property or key was provided
            const remaining = path.match(/\{([a-zA-Z0-9_]+)\}/);
            if (remaining) {
                const keys = Object.keys(params);
                if (keys.length === 1) {
                    path = path.replace(remaining[0], String(Object.values(params)[0]));
                } else if (params.subjectId || params.uuid || params.id || params.category || params.product) {
                    const fallbackVal =
                        params.subjectId || params.uuid || params.id || params.category || params.product;
                    path = path.replace(remaining[0], String(fallbackVal));
                }
            }
        } else {
            path = path.replace(/\{[a-zA-Z0-9_]+\}/, String(params));
        }
    }

    return path;
}

// Make route() globally available
if (typeof window !== 'undefined') {
    (window as any).route = route;
}

/* -------------------------------------------------------------------------- */
/* Page Route Resolver & Mock Props Builder                                   */
/* -------------------------------------------------------------------------- */

function getQueryParams(): Record<string, string> {
    const params = new URLSearchParams(currentSearch.value);
    const result: Record<string, string> = {};
    params.forEach((val, key) => {
        result[key] = val;
        // If key is like filter[search], also set result['search'] for direct access
        const match = key.match(/^filter\[([^\]]+)\]$/);
        if (match) {
            const innerKey = match[1];
            if (result[innerKey] === undefined) {
                result[innerKey] = val;
            }
        }
    });
    return result;
}

function resolveRouteProps(name: string, path: string): Record<string, any> {
    const q = getQueryParams();

    switch (name) {
        case 'Dashboard': {
            const todayIso = new Date().toISOString().slice(0, 10);
            const selectedDate = (q.date as string) || todayIso;
            return {
                today: mockDashboardData.today,
                yesterday: mockDashboardData.yesterday,
                trend: mockDashboardData.trend,
                lowStock: mockDashboardData.lowStock,
                oversold: mockDashboardData.oversold,
                recentOrders: mockDashboardData.recentOrders,
                stats: mockDashboardData.stats,
                offlineToday: mockDashboardData.offlineToday,
                debts: mockDashboardData.debts,
                myself: mockDashboardData.myself,
                catalogue: mockDashboardData.catalogue,
                canSeeReports: mockDashboardData.canSeeReports,
                filters: {
                    date: selectedDate,
                    isToday: selectedDate === todayIso,
                },
            };
        }

        case 'Pos/Index':
            return {
                boot: {
                    store_id: 1,
                    store_name: 'Main Store (BKK1)',
                    cashier_id: 1,
                    cashier_name: 'Sokha Rith',
                },
            };

        case 'Products/Index': {
            let filtered = [...mockProducts];
            if (q.search) {
                const searchStr = q.search.toLowerCase();
                filtered = filtered.filter(
                    (p) =>
                        p.name.toLowerCase().includes(searchStr) ||
                        p.sku.toLowerCase().includes(searchStr) ||
                        (p.barcode && p.barcode.includes(searchStr)),
                );
            }
            if (q.category_id && q.category_id !== 'all') {
                filtered = filtered.filter((p) => String(p.category_id) === q.category_id);
            }
            return {
                products: paginate(filtered, Number(q.page) || 1, 15),
                categories: mockCategories,
                filters: {
                    search: q.search || '',
                    category_id: q.category_id || 'all',
                    status: q.status || 'all',
                },
            };
        }

        case 'Products/Create':
            return {
                categories: mockCategories,
            };

        case 'Products/Show': {
            const match = path.match(/\/products\/([^\/]+)/);
            const idOrUuid = match ? match[1] : '';
            const product =
                mockProducts.find((p) => p.uuid === idOrUuid || String(p.id) === idOrUuid) ??
                mockProducts[0];
            const pStocks = mockStocks.filter((s) => s.product_id === product.id);
            const stocks = pStocks.length ? pStocks : [
                { id: 99, uuid: 'stk-99', product_id: product.id, store_id: 1, qty: 50, low_stock_threshold: 10, store: { id: 1, name: 'Main Store (BKK1)' } },
            ];
            const movements = [
                {
                    id: 1,
                    type: 'restock',
                    qty_change: 48,
                    note: 'Initial branch opening stock',
                    created_at: '2026-10-01T08:00:00Z',
                    product: { id: product.id, name: product.name, unit: product.unit },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    creator: { id: 1, name: 'Sokha Rith (Admin)' },
                },
            ];
            return {
                product,
                packs: [],
                stocks,
                movements,
                sales: { qty: 24, revenue: '30.00' },
            };
        }

        case 'Products/Edit': {
            const match = path.match(/\/products\/([^\/]+)/);
            const idOrUuid = match ? match[1] : '';
            const product =
                mockProducts.find((p) => p.uuid === idOrUuid || String(p.id) === idOrUuid) ??
                mockProducts[0];
            const pStocks = mockStocks.filter((s) => s.product_id === product.id);
            const stocks = pStocks.length ? pStocks : [
                { id: 99, uuid: 'stk-99', product_id: product.id, store_id: 1, qty: 50, low_stock_threshold: 10, store: { id: 1, name: 'Main Store (BKK1)' } },
            ];
            return {
                product,
                categories: mockCategories,
                packs: [],
                stores: mockStores.map((s) => ({ id: s.id, name: s.name })),
                stocks,
            };
        }

        case 'Orders/Index': {
            let filtered = [...mockOrders];
            if (q.search) {
                const s = q.search.toLowerCase();
                filtered = filtered.filter((o) => o.order_no.toLowerCase().includes(s));
            }
            return {
                orders: paginate(filtered, Number(q.page) || 1, 15),
                filters: {
                    search: q.search || '',
                    status: q.status || 'all',
                    method: q.method || 'all',
                    from: q.from || '',
                    to: q.to || '',
                },
                statuses: [
                    { value: 'all', label: 'All Statuses' },
                    { value: 'completed', label: 'Completed' },
                    { value: 'refunded', label: 'Refunded' },
                    { value: 'cancelled', label: 'Cancelled' },
                ],
                methods: [
                    { value: 'all', label: 'All Payment Methods' },
                    { value: 'cash', label: 'Cash' },
                    { value: 'qr', label: 'Bakong KHQR' },
                    { value: 'debt', label: 'Debt Tab' },
                ],
            };
        }

        case 'Orders/Show': {
            const match = path.match(/\/orders\/([^\/]+)/);
            const idOrUuid = match ? match[1] : '';
            const order =
                mockOrders.find((o) => o.uuid === idOrUuid || String(o.id) === idOrUuid || o.order_no === idOrUuid) ??
                mockOrders[0];
            return {
                order,
                settings: {
                    receipt_header: 'Smile Mart Cambodia - BKK1 Branch',
                    receipt_footer: 'Thank you for your purchase!',
                    currency: mockSharedData.currency,
                },
                outstanding: order.sale_type === 'debt' ? '24.50' : '0.00',
            };
        }

        case 'Debts/Index': {
            let filtered = [...mockDebts];
            if (q.search) {
                const s = q.search.toLowerCase();
                filtered = filtered.filter(
                    (d) =>
                        d.order_no.toLowerCase().includes(s) ||
                        (d.customer && d.customer.name.toLowerCase().includes(s)),
                );
            }
            return {
                debts: paginate(filtered, Number(q.page) || 1, 15),
                filters: { search: q.search || '', state: q.state || 'unpaid' },
                summary: { open_count: mockDebts.length, owed: '34.70' },
                methods: [
                    { value: 'cash', label: 'Cash' },
                    { value: 'qr', label: 'Bakong KHQR' },
                ],
            };
        }

        case 'Inventory/Index': {
            const stockRows = mockStocks.map((s) => {
                const prod = mockProducts.find((p) => p.id === s.product_id) ?? null;
                const store = mockStores.find((st) => st.id === s.store_id) ?? null;
                return {
                    id: s.id,
                    uuid: s.uuid,
                    qty: s.qty,
                    low_stock_threshold: s.low_stock_threshold,
                    product: prod
                        ? {
                              id: prod.id,
                              name: prod.name,
                              sku: prod.sku,
                              barcode: prod.barcode,
                              unit: prod.unit,
                              case_size: prod.case_size ?? null,
                              packs: [],
                          }
                        : null,
                    store: store ? { id: store.id, name: store.name } : null,
                };
            });

            let filteredStocks = stockRows;
            if (q.search) {
                const s = q.search.toLowerCase();
                filteredStocks = filteredStocks.filter(
                    (st) =>
                        st.product?.name.toLowerCase().includes(s) ||
                        st.product?.sku.toLowerCase().includes(s) ||
                        st.product?.barcode?.includes(s),
                );
            }
            if (q.store_id && q.store_id !== 'all') {
                filteredStocks = filteredStocks.filter((st) => String(st.store?.id) === q.store_id);
            }

            const mockMovements = [
                {
                    id: 1,
                    type: 'restock',
                    qty_change: 48,
                    note: 'Supplier restock invoice #INV-8821',
                    created_at: '2026-10-08T09:30:00Z',
                    product: { id: 1, name: 'Coca-Cola Original 330ml Can', unit: 'can' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    creator: { id: 1, name: 'Sokha Rith (Admin)' },
                },
                {
                    id: 2,
                    type: 'sale',
                    qty_change: -4,
                    note: 'Sale ORD-2026-0045',
                    created_at: '2026-10-09T18:45:00Z',
                    product: { id: 5, name: 'Vital Premium Water 500ml', unit: 'bottle' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    creator: { id: 3, name: 'Bopha Chen' },
                },
            ];

            return {
                stocks: paginate(filteredStocks, Number(q.page) || 1, 15),
                stores: mockStores.map((s) => ({ id: s.id, name: s.name })),
                movements: mockMovements,
                summary: {
                    tracked: mockProducts.filter((p) => p.track_stock).length,
                    units: mockStocks.reduce((sum, s) => sum + s.qty, 0),
                    low: mockStocks.filter(
                        (s) => s.low_stock_threshold !== null && s.qty <= s.low_stock_threshold && s.qty > 0,
                    ).length,
                    out: mockStocks.filter((s) => s.qty === 0).length,
                    oversold: mockStocks.filter((s) => s.qty < 0).length,
                },
                filters: {
                    search: q.search || '',
                    store_id: q.store_id || 'all',
                    state: q.state || 'all',
                    sort: q.sort || 'qty',
                },
            };
        }

        case 'Categories/Index': {
            const searchVal = (q.search || q['filter[search]'] || '').toLowerCase();
            let filtered = [...mockCategories];
            if (searchVal) {
                filtered = filtered.filter((c) => c.name.toLowerCase().includes(searchVal));
            }
            return {
                categories: filtered,
                filters: {
                    search: q.search || q['filter[search]'] || '',
                },
            };
        }

        case 'Customers/Index': {
            let filtered = [...mockCustomers];
            if (q.search) {
                const s = q.search.toLowerCase();
                filtered = filtered.filter(
                    (c) =>
                        c.name.toLowerCase().includes(s) ||
                        (c.phone && c.phone.includes(s)) ||
                        (c.email && c.email.toLowerCase().includes(s)),
                );
            }
            return {
                customers: paginate(filtered, Number(q.page) || 1, 15),
                filters: {
                    search: q.search || '',
                },
            };
        }

        case 'Stores/Index':
            return {
                stores: mockStores,
                canManage: true,
            };

        case 'Users/Index': {
            const permissionOptions = [
                {
                    value: 'pos',
                    label: 'Point of Sale (Till)',
                    group: 'Operations',
                    defaults: { admin: true, manager: true, cashier: true },
                    actionDefaults: {},
                },
                {
                    value: 'orders',
                    label: 'Orders & Receipts',
                    group: 'Operations',
                    defaults: { admin: true, manager: true, cashier: true },
                    actionDefaults: {},
                },
                {
                    value: 'products',
                    label: 'Products & Catalogue',
                    group: 'Inventory',
                    defaults: { admin: true, manager: true, cashier: false },
                    actionDefaults: {},
                },
                {
                    value: 'categories',
                    label: 'Categories',
                    group: 'Inventory',
                    defaults: { admin: true, manager: true, cashier: false },
                    actionDefaults: {},
                },
                {
                    value: 'inventory',
                    label: 'Stock Levels & Movements',
                    group: 'Inventory',
                    defaults: { admin: true, manager: true, cashier: false },
                    actionDefaults: {},
                },
                {
                    value: 'customers',
                    label: 'Customers & Loyalty',
                    group: 'Relationships',
                    defaults: { admin: true, manager: true, cashier: true },
                    actionDefaults: {},
                },
                {
                    value: 'debts',
                    label: 'Customer Debts (Credit)',
                    group: 'Relationships',
                    defaults: { admin: true, manager: true, cashier: false },
                    actionDefaults: {},
                },
                {
                    value: 'reports',
                    label: 'Sales Reports & Analytics',
                    group: 'Management',
                    defaults: { admin: true, manager: true, cashier: false },
                    actionDefaults: {},
                },
                {
                    value: 'stores',
                    label: 'Store Branches',
                    group: 'Management',
                    defaults: { admin: true, manager: false, cashier: false },
                    actionDefaults: {},
                },
                {
                    value: 'users',
                    label: 'Staff & Permissions',
                    group: 'Management',
                    defaults: { admin: true, manager: false, cashier: false },
                    actionDefaults: {},
                },
            ];

            const actionOptions = [
                { value: 'view', label: 'View' },
                { value: 'create', label: 'Create' },
                { value: 'edit', label: 'Edit' },
                { value: 'delete', label: 'Delete' },
            ];

            const roles = [
                { value: 'admin', label: 'Admin (Full Access)' },
                { value: 'manager', label: 'Store Manager' },
                { value: 'cashier', label: 'Cashier' },
            ];

            const defaultActions = { view: true, create: true, edit: true, delete: true };
            const cashierActions = { view: true, create: true, edit: false, delete: false };

            const enrichedUsers = mockUsers.map((u) => ({
                ...u,
                store: mockStores.find((st) => st.id === u.store_id) || null,
                effective_permissions: Object.fromEntries(
                    permissionOptions.map((opt) => [
                        opt.value,
                        {
                            allowed: u.role === 'admin' ? true : u.role === 'manager' ? opt.defaults.manager : opt.defaults.cashier,
                            actions: u.role === 'admin' ? defaultActions : u.role === 'manager' ? defaultActions : cashierActions,
                        },
                    ]),
                ),
            }));

            let filteredUsers = enrichedUsers;
            if (q.search) {
                const s = q.search.toLowerCase();
                filteredUsers = filteredUsers.filter(
                    (u) => u.name.toLowerCase().includes(s) || u.email.toLowerCase().includes(s),
                );
            }
            if (q.role && q.role !== 'all') {
                filteredUsers = filteredUsers.filter((u) => u.role === q.role);
            }

            return {
                users: paginate(filteredUsers, Number(q.page) || 1, 15),
                stores: mockStores,
                roles,
                permissionOptions,
                actionOptions,
                filters: {
                    search: q.search || '',
                    role: q.role || 'all',
                },
            };
        }

        case 'Reports/Index':
            return {
                filters: {
                    from: q.from || new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10),
                    to: q.to || new Date().toISOString().slice(0, 10),
                },
                totals: {
                    orders: 284,
                    sales: '8,420.50',
                    items: 642,
                    basket: '29.65',
                },
                byDay: [
                    { day: '2026-10-05', orders: 36, sales: '1,045.20' },
                    { day: '2026-10-06', orders: 42, sales: '1,280.00' },
                    { day: '2026-10-07', orders: 39, sales: '1,120.50' },
                    { day: '2026-10-08', orders: 48, sales: '1,450.00' },
                    { day: '2026-10-09', orders: 55, sales: '1,890.80' },
                    { day: '2026-10-10', orders: 64, sales: '2,150.00' },
                ],
                byProduct: mockProducts.slice(0, 6).map((p, idx) => ({
                    product_name: p.name,
                    qty: 40 - idx * 5,
                    revenue: ((40 - idx * 5) * parseFloat(p.sell_price)).toFixed(2),
                })),
                byPayment: [
                    { method: 'cash', count: 180, amount: '4,890.00' },
                    { method: 'qr', count: 92, amount: '3,285.50' },
                    { method: 'card', count: 48, amount: '1,520.00' },
                    { method: 'credit', count: 12, amount: '245.00' },
                ],
            };

        case 'Myself/Index':
        case 'Consumption/Index': {
            const myselfOrders = mockOrders.filter((o) => o.sale_type === 'myself');
            let rows = myselfOrders.map((o) => ({
                id: o.id,
                uuid: o.uuid,
                order_no: o.order_no,
                total: o.total,
                created_at: o.created_at,
                created_offline_at: o.created_offline_at,
                cashier: o.cashier,
                items: o.items.map((i) => ({
                    id: i.id,
                    product_name: i.product_name,
                    qty: i.qty,
                })),
            }));

            if (q.search) {
                const s = q.search.toLowerCase();
                rows = rows.filter(
                    (r) =>
                        r.order_no.toLowerCase().includes(s) ||
                        r.items.some((item) => item.product_name.toLowerCase().includes(s)),
                );
            }

            return {
                rows: paginate(rows, Number(q.page) || 1, 15),
                filters: {
                    search: q.search || '',
                    from: q.from || '',
                    to: q.to || '',
                },
                summary: mockDashboardData.myself,
            };
        }

        case 'Menu/Index': {
            const menuProducts = mockProducts.map((p) => ({
                id: p.id,
                name: p.name,
                packs: [],
                description: p.description,
                image: p.image,
                unit: p.unit,
                category_id: p.category_id,
                category_name: mockCategories.find((c) => c.id === p.category_id)?.name ?? null,
                price: parseFloat(p.sell_price),
                sold_out: !p.is_active || (p.track_stock && (p.stock_qty ?? 0) <= 0),
            }));

            return {
                products: menuProducts,
                categories: mockCategories.map((c) => ({ id: c.id, name: c.name })),
                filters: {
                    search: q.search || '',
                    category: q.category ? Number(q.category) : null,
                },
                shop: {
                    name: 'Smile Mart Cambodia',
                    footer: 'Thank you for your visit! Powered by Smile Mart.',
                    currency: mockSharedData.currency,
                },
            };
        }

        case 'Activity/Index': {
            const mockActivities = [
                {
                    id: 1,
                    log_name: 'model',
                    description: 'Updated price and inventory for "Coca-Cola Original 330ml Can"',
                    event: 'updated',
                    subject_type: 'Product',
                    subject_id: 1,
                    causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@posretail.kh' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    ip_address: '192.168.1.10',
                    changes: [
                        { field: 'sell_price', from: '0.45', to: '0.50' },
                    ],
                    properties: { sku: 'BEV-COKE-330' },
                    created_at: '2026-10-10T14:30:00Z',
                },
                {
                    id: 2,
                    log_name: 'money',
                    description: 'Recorded till sale ORD-2026-0046 (18.90 USD)',
                    event: 'sale',
                    subject_type: 'Order',
                    subject_id: 102,
                    causer: { id: 3, name: 'Bopha Chen (Cashier)', email: 'cashier@posretail.kh' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    ip_address: '192.168.1.12',
                    changes: [],
                    properties: { total: '$18.90', payment: 'KHQR', items_count: '5' },
                    created_at: '2026-10-10T13:45:00Z',
                },
                {
                    id: 3,
                    log_name: 'model',
                    description: 'Adjusted stock +48 for Malinda Jasmine Rice 5kg',
                    event: 'updated',
                    subject_type: 'Stock',
                    subject_id: 1,
                    causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@posretail.kh' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    ip_address: '192.168.1.10',
                    changes: [
                        { field: 'qty', from: '12', to: '60' },
                    ],
                    properties: { reason: 'Delivery shipment in' },
                    created_at: '2026-10-10T11:20:00Z',
                },
                {
                    id: 4,
                    log_name: 'model',
                    description: 'Created customer "Sopheap Chen"',
                    event: 'created',
                    subject_type: 'Customer',
                    subject_id: 1,
                    causer: { id: 3, name: 'Bopha Chen (Cashier)', email: 'cashier@posretail.kh' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    ip_address: '192.168.1.12',
                    changes: [],
                    properties: { phone: '012 889 123', email: 'sopheap.chen@gmail.com' },
                    created_at: '2026-10-10T10:00:00Z',
                },
                {
                    id: 5,
                    log_name: 'auth',
                    description: 'Staff logged in to Till 01 (Front)',
                    event: 'login',
                    subject_type: 'User',
                    subject_id: 3,
                    causer: { id: 3, name: 'Bopha Chen (Cashier)', email: 'cashier@posretail.kh' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    ip_address: '192.168.1.12',
                    changes: [],
                    properties: { register: 'Till 01 (Front)' },
                    created_at: '2026-10-10T08:00:00Z',
                },
                {
                    id: 6,
                    log_name: 'money',
                    description: 'Recorded customer debt settlement of $15.00',
                    event: 'updated',
                    subject_type: 'Order',
                    subject_id: 104,
                    causer: { id: 2, name: 'Vannak Keo (Manager)', email: 'manager@posretail.kh' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    ip_address: '192.168.1.15',
                    changes: [
                        { field: 'paid_amount', from: '0.00', to: '15.00' },
                    ],
                    properties: { order_no: 'ORD-2026-0044', method: 'Cash' },
                    created_at: '2026-10-09T16:30:00Z',
                },
                {
                    id: 7,
                    log_name: 'model',
                    description: 'Created category "Snacks & Confectionery"',
                    event: 'created',
                    subject_type: 'Category',
                    subject_id: 2,
                    causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@posretail.kh' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    ip_address: '192.168.1.10',
                    changes: [],
                    properties: { name: 'Snacks & Confectionery' },
                    created_at: '2026-10-09T11:00:00Z',
                },
                {
                    id: 8,
                    log_name: 'access',
                    description: 'Updated store assignment for Dara Som',
                    event: 'updated',
                    subject_type: 'User',
                    subject_id: 4,
                    causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@posretail.kh' },
                    store: { id: 2, name: 'Tuol Kork Branch' },
                    ip_address: '192.168.1.10',
                    changes: [
                        { field: 'store_id', from: 'Main Store (BKK1)', to: 'Tuol Kork Branch' },
                    ],
                    properties: { role: 'Cashier' },
                    created_at: '2026-10-08T14:20:00Z',
                },
                {
                    id: 9,
                    log_name: 'model',
                    description: 'Updated low stock threshold for "Vital Premium Water 500ml"',
                    event: 'updated',
                    subject_type: 'Stock',
                    subject_id: 3,
                    causer: { id: 2, name: 'Vannak Keo (Manager)', email: 'manager@posretail.kh' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    ip_address: '192.168.1.15',
                    changes: [
                        { field: 'low_stock_threshold', from: '10', to: '30' },
                    ],
                    properties: { current_stock: '45' },
                    created_at: '2026-10-08T10:15:00Z',
                },
                {
                    id: 10,
                    log_name: 'money',
                    description: 'Completed order ORD-2026-0045 (12.50 USD)',
                    event: 'sale',
                    subject_type: 'Order',
                    subject_id: 101,
                    causer: { id: 3, name: 'Bopha Chen (Cashier)', email: 'cashier@posretail.kh' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    ip_address: '192.168.1.12',
                    changes: [],
                    properties: { total: '$12.50', payment: 'Cash' },
                    created_at: '2026-10-07T18:45:00Z',
                },
                {
                    id: 11,
                    log_name: 'access',
                    description: 'Activated register Till 02 (Express) at Main Store (BKK1)',
                    event: 'updated',
                    subject_type: 'Store',
                    subject_id: 1,
                    causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@posretail.kh' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    ip_address: '192.168.1.10',
                    changes: [
                        { field: 'is_active', from: 'false', to: 'true' },
                    ],
                    properties: { register: 'Till 02 (Express)' },
                    created_at: '2026-10-06T09:10:00Z',
                },
                {
                    id: 12,
                    log_name: 'auth',
                    description: 'Administrator logged in from internal network',
                    event: 'login',
                    subject_type: 'User',
                    subject_id: 1,
                    causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@posretail.kh' },
                    store: { id: 1, name: 'Main Store (BKK1)' },
                    ip_address: '192.168.1.10',
                    changes: [],
                    properties: { browser: 'Chrome 122', os: 'Linux' },
                    created_at: '2026-10-06T08:00:00Z',
                },
            ];

            const searchVal = (q.search || '').trim().toLowerCase();
            const logNameVal = q.log_name && q.log_name !== 'all' ? q.log_name : null;
            const eventVal = q.event && q.event !== 'all' ? q.event : null;
            const causerVal = q.causer_id && q.causer_id !== 'all' ? q.causer_id : null;
            const storeVal = q.store_id && q.store_id !== 'all' ? q.store_id : null;
            const subjectTypeVal = q.subject_type && q.subject_type !== 'all' ? q.subject_type : null;
            const subjectIdVal = q.subject_id && q.subject_id !== 'all' ? q.subject_id : null;
            const fromVal = q.from || null;
            const toVal = q.to || null;

            let filtered = [...mockActivities];

            if (searchVal) {
                filtered = filtered.filter(
                    (a) =>
                        a.description.toLowerCase().includes(searchVal) ||
                        (a.causer?.name && a.causer.name.toLowerCase().includes(searchVal)) ||
                        (a.causer?.email && a.causer.email.toLowerCase().includes(searchVal)) ||
                        (a.ip_address && a.ip_address.includes(searchVal)) ||
                        (a.subject_type && a.subject_type.toLowerCase().includes(searchVal)),
                );
            }

            if (logNameVal) {
                filtered = filtered.filter((a) => a.log_name === logNameVal);
            }

            if (eventVal) {
                filtered = filtered.filter((a) => a.event === eventVal);
            }

            if (causerVal) {
                filtered = filtered.filter((a) => a.causer && String(a.causer.id) === causerVal);
            }

            if (storeVal) {
                filtered = filtered.filter((a) => a.store && String(a.store.id) === storeVal);
            }

            if (subjectTypeVal) {
                filtered = filtered.filter(
                    (a) => a.subject_type && a.subject_type.toLowerCase() === subjectTypeVal.toLowerCase(),
                );
            }

            if (subjectIdVal) {
                filtered = filtered.filter((a) => a.subject_id && String(a.subject_id) === String(subjectIdVal));
            }

            if (fromVal) {
                filtered = filtered.filter((a) => a.created_at && a.created_at.slice(0, 10) >= fromVal);
            }

            if (toVal) {
                filtered = filtered.filter((a) => a.created_at && a.created_at.slice(0, 10) <= toVal);
            }

            return {
                activities: paginate(filtered, Number(q.page) || 1, Number(q.per_page) || 15),
                filters: {
                    search: q.search || '',
                    log_name: q.log_name || 'all',
                    event: q.event || 'all',
                    causer_id: q.causer_id || 'all',
                    store_id: q.store_id || 'all',
                    subject_type: q.subject_type || '',
                    subject_id: q.subject_id || '',
                    from: q.from || '',
                    to: q.to || '',
                },
                options: {
                    logNames: {
                        model: 'Catalogue & Stock',
                        money: 'Sales & Till',
                        access: 'Security & Staff',
                        auth: 'Authentication',
                        stock: 'Inventory',
                        sales: 'Till Sales',
                        product: 'Products',
                        category: 'Categories',
                        customer: 'Customers',
                        order: 'Orders',
                    },
                    events: ['created', 'updated', 'deleted', 'sale', 'adjustment', 'login'],
                    staff: mockUsers.map((u) => ({ id: u.id, name: u.name })),
                    stores: mockStores.map((s) => ({ id: s.id, name: s.name })),
                },
            };
        }

        case 'Activity/Show': {
            const historyMatch = path.match(
                /\/(categories|products|customers|stores|inventory|users)\/([^\/]+)\/history/,
            );

            let subjectType = 'Record';
            let subjectIdNum = 1;
            let subjectLabel = 'Item';
            let parentInfo = { title: 'Activity', href: '/activity' };
            let selfHref = path;

            if (historyMatch) {
                const section = historyMatch[1];
                const idOrUuid = historyMatch[2];

                if (section === 'categories') {
                    const cat =
                        mockCategories.find((c) => c.uuid === idOrUuid || String(c.id) === idOrUuid) ??
                        mockCategories[0];
                    subjectType = 'Category';
                    subjectIdNum = cat?.id ?? 1;
                    subjectLabel = cat?.name ?? `Category #${idOrUuid}`;
                    parentInfo = { title: 'Categories', href: '/categories' };
                    selfHref = `/categories/${idOrUuid}/history`;
                } else if (section === 'products') {
                    const prd =
                        mockProducts.find((p) => p.uuid === idOrUuid || String(p.id) === idOrUuid) ??
                        mockProducts[0];
                    subjectType = 'Product';
                    subjectIdNum = prd?.id ?? 1;
                    subjectLabel = prd?.name ?? `Product #${idOrUuid}`;
                    parentInfo = { title: 'Products', href: '/products' };
                    selfHref = `/products/${idOrUuid}/history`;
                } else if (section === 'customers') {
                    const cst =
                        mockCustomers.find((c) => c.uuid === idOrUuid || String(c.id) === idOrUuid) ??
                        mockCustomers[0];
                    subjectType = 'Customer';
                    subjectIdNum = cst?.id ?? 1;
                    subjectLabel = cst?.name ?? `Customer #${idOrUuid}`;
                    parentInfo = { title: 'Customers', href: '/customers' };
                    selfHref = `/customers/${idOrUuid}/history`;
                } else if (section === 'stores') {
                    const str =
                        mockStores.find((s) => s.uuid === idOrUuid || String(s.id) === idOrUuid) ??
                        mockStores[0];
                    subjectType = 'Store';
                    subjectIdNum = str?.id ?? 1;
                    subjectLabel = str?.name ?? `Store #${idOrUuid}`;
                    parentInfo = { title: 'Stores', href: '/stores' };
                    selfHref = `/stores/${idOrUuid}/history`;
                } else if (section === 'inventory') {
                    const stock =
                        mockStocks.find((s) => s.uuid === idOrUuid || String(s.id) === idOrUuid) ??
                        mockStocks[0];
                    const prd = mockProducts.find((p) => p.id === stock?.product_id);
                    subjectType = 'Stock';
                    subjectIdNum = stock?.id ?? 1;
                    subjectLabel = prd ? `${prd.name} Stock` : `Stock #${idOrUuid}`;
                    parentInfo = { title: 'Inventory', href: '/inventory' };
                    selfHref = `/inventory/${idOrUuid}/history`;
                } else if (section === 'users') {
                    const usr =
                        mockUsers.find((u) => u.uuid === idOrUuid || String(u.id) === idOrUuid) ??
                        mockUsers[0];
                    subjectType = 'User';
                    subjectIdNum = usr?.id ?? 1;
                    subjectLabel = usr?.name ?? `User #${idOrUuid}`;
                    parentInfo = { title: 'Users', href: '/users' };
                    selfHref = `/users/${idOrUuid}/history`;
                }
            } else {
                const match = path.match(/\/activity\/([^\/]+)/);
                const activityId = match ? match[1] : '1';
                subjectType = 'Product';
                subjectIdNum = Number(activityId) || 1;
                subjectLabel = 'Coca-Cola Original 330ml Can';
                parentInfo = { title: 'Activity', href: '/activity' };
                selfHref = `/activity/${activityId}`;
            }

            let mockEntries: any[] = [];

            if (historyMatch && historyMatch[1] === 'products') {
                const prd = mockProducts.find((p) => p.uuid === historyMatch[2] || String(p.id) === historyMatch[2]);
                const oldPrice = prd ? (parseFloat(prd.sell_price) * 0.9).toFixed(2) : '0.45';
                const currentPrice = prd?.sell_price || '0.50';
                mockEntries = [
                    {
                        id: 1,
                        log_name: 'product',
                        description: `Updated price and inventory for "${subjectLabel}"`,
                        event: 'updated',
                        causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@smilemart.kh' },
                        store: { id: 1, name: 'Main Store (BKK1)' },
                        ip_address: '192.168.1.10',
                        changes: [
                            { field: 'sell_price', from: oldPrice, to: currentPrice },
                        ],
                        properties: { sku: prd?.sku || '', barcode: prd?.barcode || '—' },
                        created_at: '2026-10-10T14:30:00Z',
                    },
                    {
                        id: 2,
                        log_name: 'product',
                        description: `Restocked "${subjectLabel}" (+48 ${prd?.unit || 'unit'})`,
                        event: 'updated',
                        causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@smilemart.kh' },
                        store: { id: 1, name: 'Main Store (BKK1)' },
                        ip_address: '192.168.1.10',
                        changes: [
                            { field: 'stock_qty', from: String((prd?.stock_qty || 100) - 48), to: String(prd?.stock_qty || 100) },
                        ],
                        properties: { store: 'Main Store (BKK1)' },
                        created_at: '2026-10-09T10:15:00Z',
                    },
                    {
                        id: 3,
                        log_name: 'product',
                        description: `Created product "${subjectLabel}"`,
                        event: 'created',
                        causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@smilemart.kh' },
                        store: { id: 1, name: 'Main Store (BKK1)' },
                        ip_address: '192.168.1.10',
                        changes: [],
                        properties: { sku: prd?.sku || '', unit: prd?.unit || 'unit' },
                        created_at: '2026-10-08T09:00:00Z',
                    },
                ];
            } else if (historyMatch && historyMatch[1] === 'inventory') {
                const stock = mockStocks.find((s) => s.uuid === historyMatch[2] || String(s.id) === historyMatch[2]);
                const currentThresh = String(stock?.low_stock_threshold ?? 20);
                const currentQty = String(stock?.qty ?? 50);
                mockEntries = [
                    {
                        id: 1,
                        log_name: 'inventory',
                        description: `Updated low stock threshold for "${subjectLabel}"`,
                        event: 'updated',
                        causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@smilemart.kh' },
                        store: stock?.store ?? { id: 1, name: 'Main Store (BKK1)' },
                        ip_address: '192.168.1.10',
                        changes: [
                            { field: 'low_stock_threshold', from: '10', to: currentThresh },
                        ],
                        properties: { on_hand: currentQty },
                        created_at: '2026-10-10T15:00:00Z',
                    },
                    {
                        id: 2,
                        log_name: 'inventory',
                        description: `Adjusted stock balance for "${subjectLabel}"`,
                        event: 'updated',
                        causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@smilemart.kh' },
                        store: stock?.store ?? { id: 1, name: 'Main Store (BKK1)' },
                        ip_address: '192.168.1.10',
                        changes: [
                            { field: 'qty', from: String((stock?.qty ?? 50) - 24), to: currentQty },
                        ],
                        properties: { reason: 'Delivery stock in' },
                        created_at: '2026-10-09T11:20:00Z',
                    },
                    {
                        id: 3,
                        log_name: 'inventory',
                        description: `Initialized stock tracking for "${subjectLabel}"`,
                        event: 'created',
                        causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@smilemart.kh' },
                        store: stock?.store ?? { id: 1, name: 'Main Store (BKK1)' },
                        ip_address: '192.168.1.10',
                        changes: [],
                        properties: { initial_qty: '24' },
                        created_at: '2026-10-08T09:00:00Z',
                    },
                ];
            } else {
                mockEntries = [
                    {
                        id: 1,
                        log_name: subjectType.toLowerCase(),
                        description: `Updated ${subjectType.toLowerCase()} "${subjectLabel}"`,
                        event: 'updated',
                        causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@smilemart.kh' },
                        store: { id: 1, name: 'Main Store (BKK1)' },
                        ip_address: '192.168.1.10',
                        changes: [
                            { field: 'name', from: 'Previous ' + subjectLabel, to: subjectLabel },
                        ],
                        properties: {},
                        created_at: '2026-10-10T14:30:00Z',
                    },
                    {
                        id: 2,
                        log_name: subjectType.toLowerCase(),
                        description: `Created ${subjectType.toLowerCase()} "${subjectLabel}"`,
                        event: 'created',
                        causer: { id: 1, name: 'Sokha Rith (Admin)', email: 'admin@smilemart.kh' },
                        store: { id: 1, name: 'Main Store (BKK1)' },
                        ip_address: '192.168.1.10',
                        changes: [],
                        properties: {},
                        created_at: '2026-10-08T09:00:00Z',
                    },
                ];
            }

            return {
                entries: paginate(mockEntries, Number(q.page) || 1, 15),
                subject: { type: subjectType, id: subjectIdNum, label: subjectLabel, exists: true },
                summary: {
                    total: mockEntries.length,
                    first_at: mockEntries.length ? mockEntries[mockEntries.length - 1].created_at : null,
                    last_at: mockEntries.length ? mockEntries[0].created_at : null,
                },
                parent: parentInfo,
                self_href: selfHref,
            };
        }

        case 'Menu/Show': {
            const match = path.match(/\/menu\/([^\/]+)/);
            const idOrUuid = match ? match[1] : '';
            const p =
                mockProducts.find((p) => p.uuid === idOrUuid || String(p.id) === idOrUuid) ??
                mockProducts[0];
            return {
                product: {
                    id: p.id,
                    name: p.name,
                    description: p.description,
                    image: p.image,
                    gallery: p.gallery || [],
                    unit: p.unit,
                    category_name: mockCategories.find((c) => c.id === p.category_id)?.name ?? null,
                    price: parseFloat(p.sell_price),
                    sold_out: !p.is_active || (p.track_stock && (p.stock_qty ?? 0) <= 0),
                    packs: [],
                },
                shop: {
                    name: 'Smile Mart Cambodia',
                    footer: 'Thank you for your visit! Powered by Smile Mart.',
                    currency: mockSharedData.currency,
                },
            };
        }

        case 'auth/Login':
            return {
                canResetPassword: true,
                status: q.status || null,
            };

        case 'auth/ForgotPassword':
        case 'auth/ResetPassword':
        case 'auth/ConfirmPassword':
        case 'auth/VerifyEmail':
        case 'auth/VerifyOtp':
            return {
                status: q.status || null,
            };

        case 'settings/Shop':
            return {
                shop: {
                    receipt_header: 'Smile Mart Cambodia - BKK1 Branch',
                    receipt_footer: 'Thank you for your visit!',
                    currency: 'USD',
                    riel_per_usd: 4100,
                    order_prefix: 'ORD',
                    logo: null,
                    favicon: null,
                },
                currencies: [
                    { code: 'USD', symbol: '$', name: 'US Dollar (USD)' },
                    { code: 'KHR', symbol: '៛', name: 'Khmer Riel (KHR)' },
                ],
            };

        case 'settings/Profile':
            return {
                mustVerifyEmail: false,
                status: null,
            };

        case 'settings/Payments':
            return {
                payments: {
                    qr_provider: 'bakong_khqr',
                    khqr_account_id: 'smile_mart@acleda',
                    khqr_merchant_name: 'SMILE MART CAMBODIA',
                    khqr_merchant_city: 'Phnom Penh',
                    khqr_merchant_id: 'SM-8821',
                    khqr_acquiring_bank: 'ACLEDA Bank Plc.',
                    qr_manual_confirm: false,
                    bakong_environment: 'production',
                    bakong_token_set: true,
                    bakong_token_from_env: false,
                    bakong_calls_today: 92,
                    bakong_daily_limit: 10000,
                },
                providers: [
                    { key: 'bakong_khqr', label: 'Bakong KHQR (National QR Standard)', verifies: true },
                    { key: 'manual', label: 'Manual QR / Cashier Confirms', verifies: false },
                ],
            };

        case 'settings/Password':
        case 'settings/Appearance':
        default:
            return {};
    }
}

function matchPathToComponent(path: string): string {
    const clean = path.split('?')[0].replace(/\/+$/, '') || '/dashboard';

    if (clean === '/' || clean === '/dashboard') return 'Dashboard';
    if (clean === '/pos') return 'Pos/Index';
    if (clean.endsWith('/history')) return 'Activity/Show';
    if (clean === '/products') return 'Products/Index';
    if (clean === '/products/create') return 'Products/Create';
    if (clean.startsWith('/products/') && clean.endsWith('/edit')) return 'Products/Edit';
    if (clean.startsWith('/products/')) return 'Products/Show';
    if (clean === '/orders') return 'Orders/Index';
    if (clean.startsWith('/orders/')) return 'Orders/Show';
    if (clean === '/debts') return 'Debts/Index';
    if (clean === '/inventory') return 'Inventory/Index';
    if (clean === '/categories') return 'Categories/Index';
    if (clean === '/customers') return 'Customers/Index';
    if (clean === '/stores') return 'Stores/Index';
    if (clean === '/users') return 'Users/Index';
    if (clean === '/reports') return 'Reports/Index';
    if (clean === '/myself' || clean === '/consumption') return 'Myself/Index';
    if (clean === '/menu') return 'Menu/Index';
    if (clean.startsWith('/menu/')) return 'Menu/Show';
    if (clean === '/activity') return 'Activity/Index';
    if (clean.startsWith('/activity/')) return 'Activity/Show';
    if (clean.startsWith('/settings/shop')) return 'settings/Shop';
    if (clean.startsWith('/settings/profile')) return 'settings/Profile';
    if (clean.startsWith('/settings/password')) return 'settings/Password';
    if (clean.startsWith('/settings/payments')) return 'settings/Payments';
    if (clean.startsWith('/settings/appearance')) return 'settings/Appearance';
    if (clean === '/login') return 'auth/Login';

    return 'Dashboard';
}

async function navigateTo(targetUrl: string, options: any = {}) {
    const [pathPart, queryPart] = targetUrl.split('?');
    const newPath = pathPart.startsWith('/') ? pathPart : `/${pathPart}`;
    const newSearch = queryPart ? `?${queryPart}` : '';

    currentPath.value = newPath;
    currentSearch.value = newSearch;

    if (!options.replace) {
        window.history.pushState(null, '', newPath + newSearch);
    } else {
        window.history.replaceState(null, '', newPath + newSearch);
    }

    const componentName = matchPathToComponent(newPath);
    currentComponentName.value = componentName;

    // Set page props before mounting new component
    const resolvedProps = resolveRouteProps(componentName, newPath);
    Object.keys(currentPageProps).forEach((k) => delete currentPageProps[k]);
    Object.assign(currentPageProps, resolvedProps);

    // Load component
    const pageLoader = registeredPages[`../pages/${componentName}.vue`];
    if (pageLoader) {
        const mod = await pageLoader();
        currentComponent.value = markRaw(mod.default);
    }

    if (!options.preserveScroll) {
        window.scrollTo({ top: 0, behavior: 'instant' });
    }

    if (options.onSuccess) {
        options.onSuccess({ props: page.props });
    }
    if (options.onFinish) {
        options.onFinish();
    }
}

/* -------------------------------------------------------------------------- */
/* usePage Implementation                                                     */
/* -------------------------------------------------------------------------- */

const pageProps = computed(() => {
    return {
        ...mockSharedData,
        ...currentPageProps,
    };
});

const page = reactive({
    get props() {
        return pageProps.value;
    },
    get url() {
        return currentPath.value + currentSearch.value;
    },
    get component() {
        return currentComponentName.value;
    },
    version: '1.0.0',
});

export function usePage<T = any>(): T {
    return page as unknown as T;
}

/* -------------------------------------------------------------------------- */
/* router Implementation                                                      */
/* -------------------------------------------------------------------------- */

export const router = {
    visit(url: string, options: any = {}) {
        return navigateTo(url, options);
    },
    get(url: string, data: any = {}, options: any = {}) {
        let fullUrl = url;
        if (data && Object.keys(data).length > 0) {
            const params = new URLSearchParams();
            const appendParam = (prefix: string, value: any) => {
                if (value === undefined || value === null) return;
                if (typeof value === 'object' && !Array.isArray(value)) {
                    Object.entries(value).forEach(([subKey, subVal]) => {
                        appendParam(`${prefix}[${subKey}]`, subVal);
                    });
                } else if (Array.isArray(value)) {
                    value.forEach((subVal) => {
                        appendParam(`${prefix}[]`, subVal);
                    });
                } else {
                    params.append(prefix, String(value));
                }
            };

            Object.entries(data).forEach(([k, v]) => {
                appendParam(k, v);
            });
            const qs = params.toString();
            if (qs) {
                const delim = fullUrl.includes('?') ? '&' : '?';
                fullUrl += `${delim}${qs}`;
            }
        }
        return navigateTo(fullUrl, options);
    },
    post(url: string, data: any = {}, options: any = {}) {
        handleMockMutation('post', url, data);
        if (options.preserveScroll !== false) options.preserveScroll = true;
        return navigateTo(currentPath.value + currentSearch.value, options);
    },
    put(url: string, data: any = {}, options: any = {}) {
        handleMockMutation('put', url, data);
        if (options.preserveScroll !== false) options.preserveScroll = true;
        return navigateTo(currentPath.value + currentSearch.value, options);
    },
    patch(url: string, data: any = {}, options: any = {}) {
        handleMockMutation('patch', url, data);
        if (options.preserveScroll !== false) options.preserveScroll = true;
        return navigateTo(currentPath.value + currentSearch.value, options);
    },
    delete(url: string, options: any = {}) {
        handleMockMutation('delete', url, {});
        if (options.preserveScroll !== false) options.preserveScroll = true;
        return navigateTo(currentPath.value + currentSearch.value, options);
    },
    reload(options: any = {}) {
        return navigateTo(currentPath.value + currentSearch.value, options);
    },
    on(_event?: string, _callback?: (...args: any[]) => any) {
        return () => {};
    },
    cancel() {},
    cancelToken() {},
};

function handleMockMutation(method: string, url: string, data: any) {
    mockSharedData.flash = { success: 'Saved successfully!', error: null };

    // Auto-clear toast after 3 seconds
    setTimeout(() => {
        mockSharedData.flash = { success: null, error: null };
    }, 3000);

    // If settling debt:
    if (url.includes('debts') && url.includes('settle')) {
        const debt = mockDebts.find((d) => url.includes(String(d.id)) || url.includes(d.uuid));
        if (debt && data.amount) {
            debt.paid_amount = (Number(debt.paid_amount) + Number(data.amount)).toFixed(2);
            debt.payments.push({
                id: Date.now(),
                method: data.method || 'cash',
                amount: String(data.amount),
                reference_no: data.reference || null,
                created_at: new Date().toISOString(),
            });
        }
    }

    // If creating product:
    if (url.includes('products') && method === 'post' && data.name) {
        mockProducts.unshift({
            id: mockProducts.length + 1,
            uuid: `prd-${Date.now()}`,
            name: data.name,
            sku: data.sku || `SKU-${Date.now()}`,
            barcode: data.barcode || null,
            category_id: Number(data.category_id) || 1,
            cost_price: String(data.cost_price || '0.00'),
            sell_price: String(data.sell_price || '0.00'),
            description: data.description || null,
            unit: data.unit || 'unit',
            track_stock: data.track_stock ?? true,
            is_active: true,
            image: null,
            gallery: null,
        });
    }

    // If creating category:
    if (url.includes('categories') && method === 'post' && data.name) {
        mockCategories.push({
            id: mockCategories.length + 1,
            uuid: `cat-${Date.now()}`,
            name: data.name,
            products_count: 0,
        });
    }

    // If updating category:
    if (url.includes('categories') && (method === 'put' || method === 'patch') && data.name) {
        const cat = mockCategories.find((c) => url.includes(c.uuid) || url.includes(String(c.id)));
        if (cat) {
            cat.name = data.name;
        }
    }

    // If deleting category:
    if (url.includes('categories') && method === 'delete') {
        const idx = mockCategories.findIndex((c) => url.includes(c.uuid) || url.includes(String(c.id)));
        if (idx !== -1) {
            mockCategories.splice(idx, 1);
        }
    }

    // If updating product:
    if (url.includes('products') && (method === 'put' || method === 'patch')) {
        const prd = mockProducts.find((p) => url.includes(p.uuid) || url.includes(String(p.id)));
        if (prd) {
            if (data.name) prd.name = data.name;
            if (data.sku) prd.sku = data.sku;
            if (data.barcode !== undefined) prd.barcode = data.barcode;
            if (data.category_id) prd.category_id = Number(data.category_id);
            if (data.cost_price !== undefined) prd.cost_price = String(data.cost_price);
            if (data.sell_price !== undefined) prd.sell_price = String(data.sell_price);
            if (data.description !== undefined) prd.description = data.description;
            if (data.unit !== undefined) prd.unit = data.unit;
            if (data.track_stock !== undefined) prd.track_stock = data.track_stock;
        }
    }

    // If deleting product:
    if (url.includes('products') && method === 'delete') {
        const idx = mockProducts.findIndex((p) => url.includes(p.uuid) || url.includes(String(p.id)));
        if (idx !== -1) {
            mockProducts.splice(idx, 1);
        }
    }

    // If creating customer:
    if (url.includes('customers') && method === 'post' && data.name) {
        mockCustomers.push({
            id: mockCustomers.length + 1,
            uuid: `cst-${Date.now()}`,
            name: data.name,
            phone: data.phone || null,
            email: data.email || null,
            loyalty_points: 0,
            orders_count: 0,
            spent_total: '0.00',
        });
    }

    // If updating customer:
    if (url.includes('customers') && (method === 'put' || method === 'patch')) {
        const cst = mockCustomers.find((c) => url.includes(c.uuid) || url.includes(String(c.id)));
        if (cst) {
            if (data.name) cst.name = data.name;
            if (data.phone !== undefined) cst.phone = data.phone;
            if (data.email !== undefined) cst.email = data.email;
        }
    }

    // If deleting customer:
    if (url.includes('customers') && method === 'delete') {
        const idx = mockCustomers.findIndex((c) => url.includes(c.uuid) || url.includes(String(c.id)));
        if (idx !== -1) {
            mockCustomers.splice(idx, 1);
        }
    }

    // If updating store:
    if (url.includes('stores') && (method === 'put' || method === 'patch')) {
        const str = mockStores.find((s) => url.includes(s.uuid) || url.includes(String(s.id)));
        if (str) {
            if (data.name) str.name = data.name;
            if (data.address !== undefined) str.address = data.address;
            if (data.phone !== undefined) str.phone = data.phone;
        }
    }

    // If deleting store:
    if (url.includes('stores') && method === 'delete') {
        const idx = mockStores.findIndex((s) => url.includes(s.uuid) || url.includes(String(s.id)));
        if (idx !== -1) {
            mockStores.splice(idx, 1);
        }
    }

    // If updating user:
    if (url.includes('users') && (method === 'put' || method === 'patch')) {
        const usr = mockUsers.find((u) => url.includes(u.uuid) || url.includes(String(u.id)));
        if (usr) {
            if (data.name) usr.name = data.name;
            if (data.email !== undefined) usr.email = data.email;
            if (data.role !== undefined) usr.role = data.role;
        }
    }

    // If deleting user:
    if (url.includes('users') && method === 'delete') {
        const idx = mockUsers.findIndex((u) => url.includes(u.uuid) || url.includes(String(u.id)));
        if (idx !== -1) {
            mockUsers.splice(idx, 1);
        }
    }
}

/* -------------------------------------------------------------------------- */
/* useForm Implementation                                                     */
/* -------------------------------------------------------------------------- */

export function useForm<T extends Record<string, any>>(initialValues: T) {
    const fields = reactive({ ...initialValues });
    const isDirty = ref(false);
    const errors = reactive<Record<string, string>>({});
    const processing = ref(false);
    const wasSuccessful = ref(false);
    const recentlySuccessful = ref(false);
    let transformFn: ((data: T) => any) | null = null;

    const form = new Proxy(fields as any, {
        get(target, prop, receiver) {
            if (prop === 'data') return () => ({ ...fields });
            if (prop === 'isDirty') return isDirty.value;
            if (prop === 'errors') return errors;
            if (prop === 'hasErrors') return Object.keys(errors).length > 0;
            if (prop === 'processing') return processing.value;
            if (prop === 'wasSuccessful') return wasSuccessful.value;
            if (prop === 'recentlySuccessful') return recentlySuccessful.value;
            if (prop === 'transform') {
                return (fn: (data: T) => any) => {
                    transformFn = fn;
                    return form;
                };
            }
            if (prop === 'reset') {
                return (...keys: string[]) => {
                    if (keys.length === 0) {
                        Object.assign(fields, initialValues);
                    } else {
                        keys.forEach((k) => (fields[k] = (initialValues as any)[k]));
                    }
                };
            }
            if (prop === 'clearErrors') {
                return (...keys: string[]) => {
                    if (keys.length === 0) {
                        Object.keys(errors).forEach((k) => delete errors[k]);
                    } else {
                        keys.forEach((k) => delete errors[k]);
                    }
                };
            }
            if (prop === 'setError') {
                return (field: string, msg: string) => {
                    errors[field] = msg;
                };
            }
            if (['post', 'put', 'patch', 'delete'].includes(prop as string)) {
                return (url: string, options: any = {}) => {
                    processing.value = true;
                    wasSuccessful.value = false;
                    const payload = transformFn ? transformFn({ ...fields }) : { ...fields };

                    setTimeout(() => {
                        processing.value = false;
                        wasSuccessful.value = true;
                        recentlySuccessful.value = true;
                        setTimeout(() => (recentlySuccessful.value = false), 2000);

                        router[prop as 'post' | 'put' | 'patch' | 'delete'](url, payload, options);
                    }, 120);
                };
            }

            return Reflect.get(target, prop, receiver);
        },
        set(target, prop, value, receiver) {
            isDirty.value = true;
            return Reflect.set(target, prop, value, receiver);
        },
    });

    return form;
}

/* -------------------------------------------------------------------------- */
/* Link Component                                                             */
/* -------------------------------------------------------------------------- */

export const Link = defineComponent({
    name: 'InertiaLink',
    props: {
        href: { type: String, required: true },
        as: { type: String, default: 'a' },
        method: { type: String, default: 'get' },
        data: { type: Object, default: () => ({}) },
        replace: { type: Boolean, default: false },
        preserveScroll: { type: Boolean, default: false },
        preserveState: { type: Boolean, default: false },
    },
    setup(props, { slots, attrs }) {
        function onClick(e: MouseEvent) {
            // Allow external URLs, mailto:, and anchor links to work normally
            if (
                props.href.startsWith('http://') ||
                props.href.startsWith('https://') ||
                props.href.startsWith('mailto:') ||
                props.href.startsWith('#')
            ) {
                return;
            }

            if (e.metaKey || e.ctrlKey || e.shiftKey) return;
            e.preventDefault();

            if (props.method && props.method.toLowerCase() !== 'get') {
                router[props.method.toLowerCase() as 'post' | 'put' | 'delete'](props.href, props.data, {
                    preserveScroll: props.preserveScroll,
                    preserveState: props.preserveState,
                    replace: props.replace,
                });
            } else {
                router.visit(props.href, {
                    preserveScroll: props.preserveScroll,
                    preserveState: props.preserveState,
                    replace: props.replace,
                });
            }
        }

        return () => {
            if (props.as === 'button') {
                return h(
                    'button',
                    {
                        type: 'button',
                        onClick,
                        ...attrs,
                    },
                    slots.default ? slots.default() : undefined,
                );
            }

            return h(
                'a',
                {
                    href: props.href,
                    onClick,
                    ...attrs,
                },
                slots.default ? slots.default() : undefined,
            );
        };
    },
});

/* -------------------------------------------------------------------------- */
/* Head Component                                                             */
/* -------------------------------------------------------------------------- */

export const Head = defineComponent({
    name: 'InertiaHead',
    props: {
        title: { type: String, default: '' },
    },
    setup(props) {
        function updateTitle() {
            if (props.title) {
                document.title = appTitleFormatter ? appTitleFormatter(props.title) : `${props.title} - POS Retail`;
            }
        }

        onMounted(updateTitle);
        watch(() => props.title, updateTitle);

        return () => null;
    },
});

/* -------------------------------------------------------------------------- */
/* Helpers & createInertiaApp                                                 */
/* -------------------------------------------------------------------------- */

export async function resolvePageComponent(path: string, pages: Record<string, () => Promise<{ default: DefineComponent }>>) {
    const pageLoader = pages[path];
    if (!pageLoader) {
        throw new Error(`Page component not found: ${path}`);
    }
    return pageLoader();
}

export function createSpaApp(options: {
    title?: (title: string) => string;
    resolve: (name: string) => Promise<any>;
    setup: (context: { el: HTMLElement; App: any; props: any; plugin: any }) => void;
    progress?: any;
}) {
    if (options.title) {
        appTitleFormatter = options.title;
    }

    registeredPages = import.meta.glob<any>('../pages/**/*.vue');

    // Handle browser back/forward buttons
    window.addEventListener('popstate', () => {
        const fullUrl = window.location.pathname + window.location.search;
        navigateTo(fullUrl, { replace: true });
    });

    // Root wrapper component
    const AppWrapper = defineComponent({
        name: 'InertiaAppRoot',
        inheritAttrs: false,
        setup() {
            return () => {
                if (!currentComponent.value) return null;
                return h(currentComponent.value, page.props);
            };
        },
    });

    const el = document.getElementById('app') || document.body;

    // Load initial page
    const initialComponent = matchPathToComponent(currentPath.value);
    currentComponentName.value = initialComponent;

    console.log('[Inertia Mock] Initial component:', initialComponent);
    console.log('[Inertia Mock] Registered pages:', Object.keys(registeredPages));

    const initialLoader = registeredPages[`../pages/${initialComponent}.vue`];
    console.log('[Inertia Mock] Initial loader found:', !!initialLoader);
    
    if (initialLoader) {
        const initialProps = resolveRouteProps(initialComponent, currentPath.value);
        Object.assign(currentPageProps, initialProps);

        initialLoader().then((mod) => {
            console.log('[Inertia Mock] Component loaded:', mod);
            currentComponent.value = markRaw(mod.default);

            options.setup({
                el,
                App: AppWrapper,
                props: { initialPage: page },
                plugin: {
                    install(app: any) {
                        // eslint-disable-next-line vue/no-reserved-component-names
                        app.component('Link', Link);
                        // eslint-disable-next-line vue/no-reserved-component-names
                        app.component('Head', Head);
                        // Make route() available in templates (mirrors Ziggy behaviour)
                        app.config.globalProperties.route = route;
                    },
                },
            });
        }).catch(err => {
            console.error('[Inertia Mock] Error loading component:', err);
        });
    } else {
        console.error('[Inertia Mock] No loader found for:', `../pages/${initialComponent}.vue`);
    }
}

export const createInertiaApp = createSpaApp;
