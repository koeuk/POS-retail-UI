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
    mockVendors,
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
    'vendors.index': '/vendors',
    'vendors.show': '/vendors/{vendor}',
    'vendors.store': '/vendors',
    'vendors.update': '/vendors/{vendor}',
    'vendors.destroy': '/vendors/{vendor}',
    'reports.index': '/reports',
    'consumption.index': '/consumption',
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

export function route(name: string, params?: Record<string, any>): string {
    let path = routeMap[name];
    if (!path) {
        console.warn(`Route "${name}" not found, returning /dashboard`);
        return '/dashboard';
    }
    
    if (params) {
        Object.entries(params).forEach(([key, value]) => {
            path = path.replace(`{${key}}`, String(value));
        });
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
                vendors: mockVendors,
            };

        case 'Products/Edit':
        case 'Products/Show': {
            const match = path.match(/\/products\/([^\/]+)/);
            const idOrUuid = match ? match[1] : '';
            const product =
                mockProducts.find((p) => p.uuid === idOrUuid || String(p.id) === idOrUuid) ??
                mockProducts[0];
            return {
                product,
                categories: mockCategories,
                vendors: mockVendors,
                stockHistory: [],
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

        case 'Inventory/Index':
            return {
                inventory: paginate(mockStocks, 1, 15),
                stores: mockStores,
                filters: {},
            };

        case 'Categories/Index':
            return {
                categories: mockCategories,
            };

        case 'Customers/Index':
            return {
                customers: paginate(mockCustomers, Number(q.page) || 1, 15),
                filters: {},
            };

        case 'Stores/Index':
            return {
                stores: mockStores,
            };

        case 'Users/Index':
            return {
                users: mockUsers,
                stores: mockStores,
                vendors: mockVendors,
            };

        case 'Vendors/Index':
            return {
                vendors: mockVendors,
            };

        case 'Vendors/Show': {
            const vendor = mockVendors[0];
            return {
                vendor,
                products: mockProducts.filter((p) => p.vendor_id === vendor.id),
            };
        }

        case 'Reports/Index':
            return {
                summary: {
                    total_sales: '8,420.50',
                    total_orders: 284,
                    avg_basket: '29.65',
                    total_cost: '5,180.00',
                    gross_profit: '3,240.50',
                },
                byCategory: mockCategories.map((c, i) => ({
                    id: c.id,
                    name: c.name,
                    sales: (1200 - i * 150).toFixed(2),
                    percentage: Math.round((28 - i * 4)),
                })),
                byPayment: [
                    { method: 'Cash', count: 180, amount: '4,890.00' },
                    { method: 'Bakong KHQR', count: 92, amount: '3,285.50' },
                    { method: 'Customer Debt', count: 12, amount: '245.00' },
                ],
            };

        case 'Consumption/Index':
            return { items: [] };

        case 'Menu/Index':
            return {
                categories: mockCategories,
                products: mockProducts,
            };

        case 'Activity/Index':
            return {
                activities: paginate([
                    {
                        id: 1,
                        description: 'Created product "Coca-Cola Original 330ml Can"',
                        causer: { name: 'Sokha Rith (Admin)' },
                        created_at: '2026-10-09T18:00:00Z',
                    },
                    {
                        id: 2,
                        description: 'Recorded sale ORD-2026-0046 (18.90 USD)',
                        causer: { name: 'Bopha Chen (Cashier)' },
                        created_at: '2026-10-09T19:12:00Z',
                    },
                ]),
            };

        case 'Activity/Show': {
            const match = path.match(/\/activity\/([^\/]+)/);
            const activityId = match ? match[1] : '1';
            return {
                activity: {
                    id: activityId,
                    description: 'Created product "Coca-Cola Original 330ml Can"',
                    causer: { name: 'Sokha Rith (Admin)' },
                    created_at: '2026-10-09T18:00:00Z',
                    properties: {
                        old: {},
                        attributes: { name: 'Coca-Cola Original 330ml Can', sku: 'COKE-330', sell_price: '1.25' },
                    },
                },
            };
        }

        case 'Menu/Show': {
            const match = path.match(/\/menu\/([^\/]+)/);
            const idOrUuid = match ? match[1] : '';
            const product =
                mockProducts.find((p) => p.uuid === idOrUuid || String(p.id) === idOrUuid) ??
                mockProducts[0];
            return {
                product,
                category: mockCategories.find((c) => c.id === product.category_id),
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
                    name: 'Smile Mart Cambodia',
                    phone: '+855 23 991 234',
                    address: '#45 Street 302, Boeng Keng Kang 1, Phnom Penh',
                    receipt_header: 'Smile Mart Cambodia - BKK1 Branch',
                    receipt_footer: 'Thank you for your visit!',
                    riel_per_usd: 4100,
                },
            };

        case 'settings/Profile':
            return {
                mustVerifyEmail: false,
                status: null,
            };

        case 'settings/Payments':
            return {
                payments: {
                    bakong_account: 'smile_mart@acleda',
                    merchant_name: 'SMILE MART CAMBODIA',
                    merchant_city: 'Phnom Penh',
                    is_active: true,
                },
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
    if (clean === '/vendors') return 'Vendors/Index';
    if (clean.startsWith('/vendors/')) return 'Vendors/Show';
    if (clean === '/reports') return 'Reports/Index';
    if (clean === '/consumption') return 'Consumption/Index';
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

    // Load component
    const pageLoader = registeredPages[`../pages/${componentName}.vue`];
    if (pageLoader) {
        const mod = await pageLoader();
        currentComponent.value = markRaw(mod.default);
    }

    // Set page props
    const resolvedProps = resolveRouteProps(componentName, newPath);
    Object.keys(currentPageProps).forEach((k) => delete currentPageProps[k]);
    Object.assign(currentPageProps, resolvedProps);

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
            Object.entries(data).forEach(([k, v]) => {
                if (v !== undefined && v !== null) params.append(k, String(v));
            });
            const delim = fullUrl.includes('?') ? '&' : '?';
            fullUrl += `${delim}${params.toString()}`;
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
        initialLoader().then((mod) => {
            console.log('[Inertia Mock] Component loaded:', mod);
            currentComponent.value = markRaw(mod.default);
            const initialProps = resolveRouteProps(initialComponent, currentPath.value);
            Object.assign(currentPageProps, initialProps);

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
