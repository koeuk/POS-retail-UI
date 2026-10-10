import type {
    Category,
    Customer,
    Paginated,
    Product,
    SharedData,
    Stock,
    Store,
    User,
} from '@/types';
import { Ziggy } from '../ziggy';

/* -------------------------------------------------------------------------- */
/* Auth & Shop Identity                                                       */
/* -------------------------------------------------------------------------- */

export const mockUser: User = {
    id: 1,
    uuid: 'usr-admin-001',
    name: 'Sokha Rith (Admin)',
    email: 'admin@posretail.kh',
    role: 'admin',
    store_id: null,
    is_active: true,
    created_at: '2026-01-01T08:00:00Z',
    updated_at: '2026-10-09T08:00:00Z',
};

export const mockUsers: User[] = [
    mockUser,
    {
        id: 2,
        uuid: 'usr-mgr-002',
        name: 'Vannak Keo',
        email: 'manager@posretail.kh',
        role: 'manager',
        store_id: 1,
        is_active: true,
        created_at: '2026-02-15T09:00:00Z',
    },
    {
        id: 3,
        uuid: 'usr-csh-003',
        name: 'Bopha Chen',
        email: 'cashier@posretail.kh',
        role: 'cashier',
        store_id: 1,
        is_active: true,
        created_at: '2026-03-01T10:00:00Z',
    },
    {
        id: 4,
        uuid: 'usr-csh-004',
        name: 'Dara Som',
        email: 'dara@posretail.kh',
        role: 'cashier',
        store_id: 2,
        is_active: true,
        created_at: '2026-04-10T11:00:00Z',
    },
];

export const mockSharedData: SharedData = {
    name: 'Smile Mart Cambodia',
    quote: {
        message: 'Speed, clarity, and dual-currency power for modern Cambodian retail.',
        author: 'Smile Mart System',
    },
    auth: {
        user: mockUser,
        store_name: 'Main Store (BKK1)',
        can: {
            accessAdmin: true,
            manage: true,
            isAdmin: true,
            pos: true,
            orders: true,
            products: true,
            categories: true,
            customers: true,
            debts: true,
            inventory: true,
            stores: true,
            users: true,
            reports: true,
            activity: true,
            consumption: true,
            menu: true,
            settings: true,
        },
        actions: {
            products: { create: true, edit: true, delete: true },
            orders: { view: true, cancel: true },
            customers: { create: true, edit: true, delete: true },
            debts: { settle: true, record: true },
            inventory: { adjust: true, transfer: true },
            stores: { create: true, edit: true, delete: true },
            users: { create: true, edit: true, delete: true },
            categories: { create: true, edit: true, delete: true },
        },
    },
    flash: { success: null, error: null },
    currency: {
        code: 'USD',
        symbol: '$',
        decimals: 2,
        riel_per_usd: 4100,
    },
    branding: {
        logo: null,
        favicon: null,
    },
    ziggy: Ziggy as unknown as SharedData['ziggy'],
};

/* -------------------------------------------------------------------------- */
/* Stores & Registers                                                         */
/* -------------------------------------------------------------------------- */

export const mockStores: Store[] = [
    {
        id: 1,
        uuid: 'str-bkk1-001',
        name: 'Main Store (BKK1)',
        address: '#45 Street 302, Boeng Keng Kang 1, Phnom Penh',
        phone: '+855 23 991 234',
        registers: [
            { id: 1, uuid: 'reg-01', store_id: 1, name: 'Till 01 (Front)', is_active: true },
            { id: 2, uuid: 'reg-02', store_id: 1, name: 'Till 02 (Express)', is_active: true },
        ],
        users_count: 2,
        orders_count: 148,
    },
    {
        id: 2,
        uuid: 'str-tk-002',
        name: 'Tuol Kork Branch',
        address: '#12 Street 289, Tuol Kork, Phnom Penh',
        phone: '+855 23 882 567',
        registers: [
            { id: 3, uuid: 'reg-03', store_id: 2, name: 'Till 01 (Main)', is_active: true },
        ],
        users_count: 1,
        orders_count: 92,
    },
];

/* -------------------------------------------------------------------------- */
/* Categories                                                                 */
/* -------------------------------------------------------------------------- */

export const mockCategories: Category[] = [
    { id: 1, uuid: 'cat-bev', name: 'Beverages (ភេសជ្ជៈ)', products_count: 6 },
    { id: 2, uuid: 'cat-alc', name: 'Beer & Alcohol (ស្រាបៀរ)', products_count: 3 },
    { id: 3, uuid: 'cat-snk', name: 'Snacks & Sweets (នំចំណី)', products_count: 4 },
    { id: 4, uuid: 'cat-gro', name: 'Groceries & Rice (គ្រឿងទេស)', products_count: 5 },
    { id: 5, uuid: 'cat-dai', name: 'Dairy & Eggs (ទឹកដោះគោ)', products_count: 3 },
    { id: 6, uuid: 'cat-per', name: 'Personal Care (ថែរក្សាខ្លួន)', products_count: 2 },
];

/* -------------------------------------------------------------------------- */
/* Products                                                                   */
/* -------------------------------------------------------------------------- */

export const mockProducts: Product[] = [
    {
        id: 1,
        uuid: 'prd-coke-can',
        category_id: 1,
        name: 'Coca-Cola Original 330ml Can',
        sku: 'BEV-COKE-330',
        barcode: '8851959132014',
        description: 'Classic Coca-Cola 330ml aluminum can, chilled.',
        cost_price: '0.35',
        sell_price: '0.50',
        image: null,
        gallery: null,
        unit: 'can',
        track_stock: true,
        is_active: true,
        stock_qty: 142,
        packs_count: 2,
        pack_min_price: '2.80',
        pack_max_price: '10.50',
        category: { id: 1, name: 'Beverages (ភេសជ្ជៈ)' },
    },
    {
        id: 2,
        uuid: 'prd-coke-pack6',
        category_id: 1,
        parent_product_id: 1,
        units_per_pack: 6,
        name: 'Coca-Cola 330ml (6-Pack)',
        sku: 'BEV-COKE-PK6',
        barcode: '8851959132069',
        description: 'Shrink-wrapped 6-pack of 330ml Coca-Cola cans.',
        cost_price: '2.00',
        sell_price: '2.80',
        image: null,
        gallery: null,
        unit: 'pack',
        track_stock: false,
        is_active: true,
        category: { id: 1, name: 'Beverages (ភេសជ្ជៈ)' },
    },
    {
        id: 3,
        uuid: 'prd-angkor-can',
        category_id: 2,
        name: 'Angkor Beer 330ml Can',
        sku: 'ALC-ANGKOR-330',
        barcode: '884800010012',
        description: 'My Country, My Beer. Refreshing Cambodian premium lager.',
        cost_price: '0.55',
        sell_price: '0.75',
        image: null,
        gallery: null,
        unit: 'can',
        track_stock: true,
        is_active: true,
        stock_qty: 96,
        packs_count: 1,
        pack_min_price: '16.00',
        pack_max_price: '16.00',
        category: { id: 2, name: 'Beer & Alcohol (ស្រាបៀរ)' },
    },
    {
        id: 4,
        uuid: 'prd-hanuman-can',
        category_id: 2,
        name: 'Hanuman Premium Lager 330ml Can',
        sku: 'ALC-HANUMAN-330',
        barcode: '884800020034',
        description: 'Smooth craft-inspired premium Cambodian beer.',
        cost_price: '0.65',
        sell_price: '0.90',
        image: null,
        gallery: null,
        unit: 'can',
        track_stock: true,
        is_active: true,
        stock_qty: 68,
        category: { id: 2, name: 'Beer & Alcohol (ស្រាបៀរ)' },
    },
    {
        id: 5,
        uuid: 'prd-vital-500',
        category_id: 1,
        name: 'Vital Premium Water 500ml',
        sku: 'BEV-VITAL-500',
        barcode: '884100100019',
        description: 'Naturally purified premium drinking water.',
        cost_price: '0.15',
        sell_price: '0.25',
        image: null,
        gallery: null,
        unit: 'bottle',
        track_stock: true,
        is_active: true,
        stock_qty: 230,
        packs_count: 1,
        pack_min_price: '2.50',
        pack_max_price: '2.50',
        category: { id: 1, name: 'Beverages (ភេសជ្ជៈ)' },
    },
    {
        id: 6,
        uuid: 'prd-redbull-250',
        category_id: 1,
        name: 'Red Bull Energy Drink Gold 250ml',
        sku: 'BEV-REDBULL-250',
        barcode: '8850228000155',
        description: 'Classic gold energy drink can.',
        cost_price: '0.60',
        sell_price: '0.85',
        image: null,
        gallery: null,
        unit: 'can',
        track_stock: true,
        is_active: true,
        stock_qty: 85,
        category: { id: 1, name: 'Beverages (ភេសជ្ជៈ)' },
    },
    {
        id: 7,
        uuid: 'prd-sting-straw',
        category_id: 1,
        name: 'Sting Energy Strawberry 330ml',
        sku: 'BEV-STING-RED',
        barcode: '8934588012112',
        description: 'Strawberry flavored energy drink with ginseng.',
        cost_price: '0.40',
        sell_price: '0.60',
        image: null,
        gallery: null,
        unit: 'bottle',
        track_stock: true,
        is_active: true,
        stock_qty: 110,
        category: { id: 1, name: 'Beverages (ភេសជ្ជៈ)' },
    },
    {
        id: 8,
        uuid: 'prd-jasmine-rice-5k',
        category_id: 4,
        name: 'Malinda Phka Romduol Jasmine Rice 5kg',
        sku: 'GRO-RICE-5KG',
        barcode: '884200200055',
        description: 'World champion fragrant Cambodian jasmine rice.',
        cost_price: '4.50',
        sell_price: '6.20',
        image: null,
        gallery: null,
        unit: 'bag',
        track_stock: true,
        is_active: true,
        stock_qty: 32,
        category: { id: 4, name: 'Groceries & Rice (គ្រឿងទេស)' },
    },
    {
        id: 9,
        uuid: 'prd-mama-pork',
        category_id: 4,
        name: 'Mama Instant Noodles Minced Pork Flavor',
        sku: 'GRO-MAMA-PORK',
        barcode: '8850987101017',
        description: 'Popular Thai minced pork instant noodles.',
        cost_price: '0.22',
        sell_price: '0.35',
        image: null,
        gallery: null,
        unit: 'pack',
        track_stock: true,
        is_active: true,
        stock_qty: 180,
        category: { id: 4, name: 'Groceries & Rice (គ្រឿងទេស)' },
    },
    {
        id: 10,
        uuid: 'prd-lactasoy-250',
        category_id: 5,
        name: 'Lactasoy Soy Milk Original 250ml',
        sku: 'DAI-LACTA-250',
        barcode: '8850288000213',
        description: 'Sweetened soy milk with natural milk protein.',
        cost_price: '0.30',
        sell_price: '0.45',
        image: null,
        gallery: null,
        unit: 'box',
        track_stock: true,
        is_active: true,
        stock_qty: 74,
        category: { id: 5, name: 'Dairy & Eggs (ទឹកដោះគោ)' },
    },
    {
        id: 11,
        uuid: 'prd-bear-brand-140',
        category_id: 5,
        name: 'Nestlé Bear Brand Sterilized Milk 140ml',
        sku: 'DAI-BEAR-140',
        barcode: '8850124001221',
        description: 'Sterilized dairy milk with high calcium.',
        cost_price: '0.50',
        sell_price: '0.70',
        image: null,
        gallery: null,
        unit: 'can',
        track_stock: true,
        is_active: true,
        stock_qty: 48,
        category: { id: 5, name: 'Dairy & Eggs (ទឹកដោះគោ)' },
    },
    {
        id: 12,
        uuid: 'prd-lays-classic',
        category_id: 3,
        name: 'Lay\'s Classic Salted Potato Chips 50g',
        sku: 'SNK-LAYS-50',
        barcode: '8850718801123',
        description: 'Crispy salted potato chips.',
        cost_price: '0.70',
        sell_price: '1.00',
        image: null,
        gallery: null,
        unit: 'pack',
        track_stock: true,
        is_active: true,
        stock_qty: 42,
        category: { id: 3, name: 'Snacks & Sweets (នំចំណី)' },
    },
];

/* -------------------------------------------------------------------------- */
/* Stocks                                                                     */
/* -------------------------------------------------------------------------- */

export const mockStocks: Stock[] = [
    { id: 1, uuid: 'stk-01', product_id: 1, store_id: 1, qty: 94, low_stock_threshold: 20, store: { id: 1, name: 'Main Store (BKK1)' } },
    { id: 2, uuid: 'stk-02', product_id: 1, store_id: 2, qty: 48, low_stock_threshold: 20, store: { id: 2, name: 'Tuol Kork Branch' } },
    { id: 3, uuid: 'stk-03', product_id: 3, store_id: 1, qty: 64, low_stock_threshold: 24, store: { id: 1, name: 'Main Store (BKK1)' } },
    { id: 4, uuid: 'stk-04', product_id: 3, store_id: 2, qty: 32, low_stock_threshold: 24, store: { id: 2, name: 'Tuol Kork Branch' } },
    { id: 5, uuid: 'stk-05', product_id: 8, store_id: 1, qty: 5, low_stock_threshold: 10, store: { id: 1, name: 'Main Store (BKK1)' } }, // Low stock!
    { id: 6, uuid: 'stk-06', product_id: 11, store_id: 1, qty: 4, low_stock_threshold: 12, store: { id: 1, name: 'Main Store (BKK1)' } }, // Low stock!
];

/* -------------------------------------------------------------------------- */
/* Customers                                                                  */
/* -------------------------------------------------------------------------- */

export const mockCustomers: Customer[] = [
    {
        id: 1,
        uuid: 'cst-001',
        name: 'Sopheap Chen',
        phone: '012 889 123',
        email: 'sopheap.chen@gmail.com',
        loyalty_points: 320,
        orders_count: 14,
        spent_total: '185.50',
    },
    {
        id: 2,
        uuid: 'cst-002',
        name: 'Lok Kru Bunly (Corner Shop)',
        phone: '098 776 543',
        email: null,
        loyalty_points: 980,
        orders_count: 36,
        spent_total: '640.20',
    },
    {
        id: 3,
        uuid: 'cst-003',
        name: 'Bopha Long',
        phone: '087 452 311',
        email: 'bopha.long@khmer.com',
        loyalty_points: 150,
        orders_count: 8,
        spent_total: '92.00',
    },
    {
        id: 4,
        uuid: 'cst-004',
        name: 'Sokha Van',
        phone: '015 678 901',
        email: null,
        loyalty_points: 410,
        orders_count: 19,
        spent_total: '245.80',
    },
    {
        id: 5,
        uuid: 'cst-005',
        name: 'Rithy Meas',
        phone: '077 223 344',
        email: 'rithy.meas@yahoo.com',
        loyalty_points: 85,
        orders_count: 5,
        spent_total: '54.50',
    },
];

/* -------------------------------------------------------------------------- */
/* Orders                                                                     */
/* -------------------------------------------------------------------------- */

export interface MockOrderItem {
    id: number;
    product_name: string;
    qty: number;
    unit_price: string;
    discount: string;
    subtotal: string;
}

export interface MockOrder {
    id: number;
    uuid: string;
    order_no: string;
    total: string;
    subtotal: string;
    discount_amount: string;
    paid_amount: string;
    change_amount: string;
    status: 'completed' | 'cancelled' | 'refunded';
    sale_type: 'customer' | 'debt' | 'myself';
    items_count: number;
    created_at: string;
    created_offline_at: string | null;
    synced_at: string | null;
    cashier: { id: number; name: string } | null;
    store: { id: number; name: string; address: string | null; phone: string | null } | null;
    register: { id: number; name: string } | null;
    customer: { id: number; name: string; phone: string | null; email: string | null } | null;
    payments: { id: number; method: string; amount: string; reference_no: string | null }[];
    items: MockOrderItem[];
}

export const mockOrders: MockOrder[] = [
    {
        id: 101,
        uuid: 'ord-2026-0045',
        order_no: 'ORD-2026-0045',
        total: '12.50',
        subtotal: '12.50',
        discount_amount: '0.00',
        paid_amount: '15.00',
        change_amount: '2.50',
        status: 'completed',
        sale_type: 'customer',
        items_count: 4,
        created_at: '2026-10-09T18:45:00Z',
        created_offline_at: null,
        synced_at: '2026-10-09T18:45:02Z',
        cashier: { id: 3, name: 'Bopha Chen' },
        store: { id: 1, name: 'Main Store (BKK1)', address: '#45 Street 302, Phnom Penh', phone: '+855 23 991 234' },
        register: { id: 1, name: 'Till 01 (Front)' },
        customer: { id: 1, name: 'Sopheap Chen', phone: '012 889 123', email: 'sopheap.chen@gmail.com' },
        payments: [{ id: 1, method: 'cash', amount: '15.00', reference_no: null }],
        items: [
            { id: 1, product_name: 'Malinda Jasmine Rice 5kg', qty: 1, unit_price: '6.20', discount: '0.00', subtotal: '6.20' },
            { id: 2, product_name: 'Coca-Cola (6-Pack)', qty: 1, unit_price: '2.80', discount: '0.00', subtotal: '2.80' },
            { id: 3, product_name: 'Vital Premium Water 500ml', qty: 4, unit_price: '0.25', discount: '0.00', subtotal: '1.00' },
            { id: 4, product_name: 'Lay\'s Classic Potato Chips 50g', qty: 2, unit_price: '1.00', discount: '0.00', subtotal: '2.00' },
        ],
    },
    {
        id: 102,
        uuid: 'ord-2026-0046',
        order_no: 'ORD-2026-0046',
        total: '18.90',
        subtotal: '18.90',
        discount_amount: '0.00',
        paid_amount: '18.90',
        change_amount: '0.00',
        status: 'completed',
        sale_type: 'customer',
        items_count: 5,
        created_at: '2026-10-09T19:12:00Z',
        created_offline_at: null,
        synced_at: '2026-10-09T19:12:01Z',
        cashier: { id: 3, name: 'Bopha Chen' },
        store: { id: 1, name: 'Main Store (BKK1)', address: '#45 Street 302, Phnom Penh', phone: '+855 23 991 234' },
        register: { id: 1, name: 'Till 01 (Front)' },
        customer: { id: 4, name: 'Sokha Van', phone: '015 678 901', email: null },
        payments: [{ id: 2, method: 'qr', amount: '18.90', reference_no: 'KHQR-8823199' }],
        items: [
            { id: 5, product_name: 'Angkor Beer 330ml Can', qty: 12, unit_price: '0.75', discount: '0.00', subtotal: '9.00' },
            { id: 6, product_name: 'Hanuman Premium Lager 330ml', qty: 6, unit_price: '0.90', discount: '0.00', subtotal: '5.40' },
            { id: 7, product_name: 'Red Bull Energy Drink 250ml', qty: 3, unit_price: '0.85', discount: '0.00', subtotal: '2.55' },
            { id: 8, product_name: 'Vital Premium Water 500ml', qty: 4, unit_price: '0.25', discount: '0.00', subtotal: '1.00' },
        ],
    },
    {
        id: 103,
        uuid: 'ord-2026-0047',
        order_no: 'ORD-2026-0047',
        total: '34.50',
        subtotal: '34.50',
        discount_amount: '0.00',
        paid_amount: '10.00',
        change_amount: '0.00',
        status: 'completed',
        sale_type: 'debt',
        items_count: 3,
        created_at: '2026-10-09T19:30:00Z',
        created_offline_at: null,
        synced_at: '2026-10-09T19:30:02Z',
        cashier: { id: 3, name: 'Bopha Chen' },
        store: { id: 1, name: 'Main Store (BKK1)', address: '#45 Street 302, Phnom Penh', phone: '+855 23 991 234' },
        register: { id: 1, name: 'Till 01 (Front)' },
        customer: { id: 2, name: 'Lok Kru Bunly (Corner Shop)', phone: '098 776 543', email: null },
        payments: [{ id: 3, method: 'cash', amount: '10.00', reference_no: null }],
        items: [
            { id: 9, product_name: 'Malinda Jasmine Rice 5kg', qty: 3, unit_price: '6.20', discount: '0.00', subtotal: '18.60' },
            { id: 10, product_name: 'Coca-Cola (6-Pack)', qty: 3, unit_price: '2.80', discount: '0.00', subtotal: '8.40' },
            { id: 11, product_name: 'Angkor Beer 330ml Can', qty: 10, unit_price: '0.75', discount: '0.00', subtotal: '7.50' },
        ],
    },
];

/* -------------------------------------------------------------------------- */
/* Debts                                                                      */
/* -------------------------------------------------------------------------- */

export interface MockDebt {
    id: number;
    uuid: string;
    order_no: string;
    total: string;
    paid_amount: string;
    items_count: number;
    created_at: string;
    created_offline_at: string | null;
    customer: { id: number; name: string; phone: string | null } | null;
    cashier: { id: number; name: string } | null;
    items: { id: number; product_name: string; qty: number; unit_price: string; subtotal: string }[];
    payments: { id: number; method: string; amount: string; reference_no: string | null; created_at: string }[];
}

export const mockDebts: MockDebt[] = [
    {
        id: 103,
        uuid: 'ord-2026-0047',
        order_no: 'ORD-2026-0047',
        total: '34.50',
        paid_amount: '10.00',
        items_count: 3,
        created_at: '2026-10-09T19:30:00Z',
        created_offline_at: null,
        customer: { id: 2, name: 'Lok Kru Bunly (Corner Shop)', phone: '098 776 543' },
        cashier: { id: 3, name: 'Bopha Chen' },
        items: [
            { id: 9, product_name: 'Malinda Jasmine Rice 5kg', qty: 3, unit_price: '6.20', subtotal: '18.60' },
            { id: 10, product_name: 'Coca-Cola (6-Pack)', qty: 3, unit_price: '2.80', subtotal: '8.40' },
            { id: 11, product_name: 'Angkor Beer 330ml Can', qty: 10, unit_price: '0.75', subtotal: '7.50' },
        ],
        payments: [
            { id: 3, method: 'cash', amount: '10.00', reference_no: null, created_at: '2026-10-09T19:30:00Z' },
        ],
    },
    {
        id: 98,
        uuid: 'ord-2026-0038',
        order_no: 'ORD-2026-0038',
        total: '15.20',
        paid_amount: '5.00',
        items_count: 2,
        created_at: '2026-10-08T15:10:00Z',
        created_offline_at: null,
        customer: { id: 5, name: 'Rithy Meas', phone: '077 223 344' },
        cashier: { id: 3, name: 'Bopha Chen' },
        items: [
            { id: 12, product_name: 'Coca-Cola (6-Pack)', qty: 2, unit_price: '2.80', subtotal: '5.60' },
            { id: 13, product_name: 'Hanuman Premium Lager 330ml', qty: 10, unit_price: '0.90', subtotal: '9.00' },
        ],
        payments: [
            { id: 4, method: 'cash', amount: '5.00', reference_no: null, created_at: '2026-10-08T15:10:00Z' },
        ],
    },
];

/* -------------------------------------------------------------------------- */
/* Dashboard Data                                                             */
/* -------------------------------------------------------------------------- */

export const mockDashboardData = {
    today: {
        sales: '1420.50',
        orders: 48,
        basket: '29.59',
        items: 134,
    },
    yesterday: {
        sales: '1280.00',
        orders: 42,
        basket: '30.47',
        items: 118,
    },
    trend: [
        { day: 'Oct 03', orders: 38, sales: '980.00' },
        { day: 'Oct 04', orders: 44, sales: '1150.00' },
        { day: 'Oct 05', orders: 51, sales: '1320.00' },
        { day: 'Oct 06', orders: 40, sales: '1050.00' },
        { day: 'Oct 07', orders: 46, sales: '1210.00' },
        { day: 'Oct 08', orders: 42, sales: '1280.00' },
        { day: 'Oct 09', orders: 48, sales: '1420.50' },
    ],
    lowStock: [
        {
            id: 5,
            qty: 5,
            low_stock_threshold: 10,
            product: { id: 8, name: 'Malinda Phka Romduol Jasmine Rice 5kg', unit: 'bag' },
            store: { id: 1, name: 'Main Store (BKK1)' },
        },
        {
            id: 6,
            qty: 4,
            low_stock_threshold: 12,
            product: { id: 11, name: 'Nestlé Bear Brand Sterilized Milk 140ml', unit: 'can' },
            store: { id: 1, name: 'Main Store (BKK1)' },
        },
    ],
    oversold: [],
    recentOrders: [
        {
            id: 102,
            order_no: 'ORD-2026-0046',
            total: '18.90',
            created_offline_at: null,
            cashier: { id: 3, name: 'Bopha Chen' },
        },
        {
            id: 101,
            order_no: 'ORD-2026-0045',
            total: '12.50',
            created_offline_at: null,
            cashier: { id: 3, name: 'Bopha Chen' },
        },
        {
            id: 100,
            order_no: 'ORD-2026-0044',
            total: '6.20',
            created_offline_at: null,
            cashier: { id: 4, name: 'Dara Som' },
        },
        {
            id: 99,
            order_no: 'ORD-2026-0043',
            total: '24.10',
            created_offline_at: null,
            cashier: { id: 3, name: 'Bopha Chen' },
        },
    ],
    stats: {
        products_count: 12,
        customers_count: 5,
        stores_count: 2,
        low_stock_count: 2,
    },
    offlineToday: 0,
    debts: {
        count: 2,
        owed: '34.70',
    },
    myself: {
        week: { count: 3, value: '18.50' },
        month: { count: 12, value: '64.00' },
        year: { count: 140, value: '720.00' },
    },
    catalogue: {
        products: 12,
        categories: 5,
    },
    canSeeReports: true,
    filters: {
        date: new Date().toISOString().slice(0, 10),
        isToday: true,
    },
};

/* -------------------------------------------------------------------------- */
/* Helpers for Paginated formatting                                           */
/* -------------------------------------------------------------------------- */

export function paginate<T>(items: T[], page = 1, perPage = 15): Paginated<T> {
    const total = items.length;
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const currentPage = Math.min(Math.max(1, page), lastPage);
    const from = total === 0 ? null : (currentPage - 1) * perPage + 1;
    const to = total === 0 ? null : Math.min(currentPage * perPage, total);
    const data = items.slice((currentPage - 1) * perPage, currentPage * perPage);

    return {
        data,
        current_page: currentPage,
        last_page: lastPage,
        per_page: perPage,
        from,
        to,
        total,
        links: [
            { url: currentPage > 1 ? `?page=${currentPage - 1}` : null, label: '&laquo; Previous', active: false },
            { url: `?page=1`, label: '1', active: currentPage === 1 },
            { url: currentPage < lastPage ? `?page=${currentPage + 1}` : null, label: 'Next &raquo;', active: false },
        ],
    };
}
