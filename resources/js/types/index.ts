import type { LucideIcon } from 'lucide-vue-next';

export type Role = 'admin' | 'manager' | 'cashier';

export interface Auth {
    user: User | null;
    /** The shop this person is bound to; null for admins, who see them all. */
    store_name: string | null;
    can: {
        accessAdmin: boolean;
        manage: boolean;
        isAdmin: boolean;
        /** One resolved flag per feature permission (pos, orders, reports…). */
        [permission: string]: boolean;
    };
    /**
     * What may be done inside each area, e.g. `actions.products.delete`.
     * Buttons render from this; the policies are what actually enforce it.
     */
    actions: Record<string, Record<string, boolean>>;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
    /** Hide from users without this capability or feature permission. */
    requires?: string;
}

export interface NavGroup {
    label: string;
    items: NavItem[];
}

export interface SharedData {
    /* Inertia's usePage<T>() constrains T to PageProps, which requires an
       index signature. Without it every usePage<SharedData>() call fails. */
    [key: string]: unknown;
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: { success: string | null; error: string | null };
    /** The shop's display currency; stored prices are always USD. */
    currency: { code: string; symbol: string; decimals: number; riel_per_usd: number };
    /** Uploaded shop branding — paths on the public disk, or null for the built-in look. */
    branding: { logo: string | null; favicon: string | null };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    /** Public identity — what URLs and route bindings use. */
    uuid: string;
    name: string;
    email: string;
    avatar?: string;
    role: Role;
    store_id: number | null;
    is_active: boolean;
    email_verified_at?: string | null;
    created_at?: string;
    updated_at?: string;
}

/* -------------------------------------------------------------------------- */
/* Domain                                                                      */
/* -------------------------------------------------------------------------- */

export interface Store {
    id: number;
    uuid: string;
    name: string;
    address: string | null;
    phone: string | null;
    registers?: Register[];
    users_count?: number;
    orders_count?: number;
}

export interface Register {
    id: number;
    uuid: string;
    store_id: number;
    name: string;
    is_active: boolean;
}

export interface Category {
    id: number;
    uuid: string;
    name: string;
    products_count?: number;
}

export interface Product {
    id: number;
    uuid: string;
    category_id: number;
    /** Set when this row is a pack of another product — a case of the base unit. */
    parent_product_id?: number | null;
    parent?: Pick<Product, 'id' | 'name'> | null;
    /** Base units one of these contains. 1 for a base product. */
    units_per_pack?: number;
    case_size?: number | null;
    packs_count?: number;
    /** Cheapest and dearest pack price, for the range shown in the list. */
    pack_min_price?: string | null;
    pack_max_price?: string | null;
    category?: Pick<Category, 'id' | 'name'>;
    name: string;
    sku: string;
    barcode: string | null;
    description: string | null;
    cost_price: string;
    sell_price: string;
    image: string | null;
    /** Extra photos: public-disk paths or full external URLs. */
    gallery: string[] | null;
    unit: string;
    track_stock: boolean;
    is_active: boolean;
    /** Summed across stores by the index query. */
    stock_qty?: number | null;
}

export interface Stock {
    id: number;
    uuid: string;
    product_id: number;
    store_id: number;
    qty: number;
    low_stock_threshold: number | null;
    store?: Pick<Store, 'id' | 'name'>;
}

export interface Customer {
    id: number;
    uuid: string;
    name: string;
    phone: string | null;
    email: string | null;
    loyalty_points: number;
    orders_count?: number;
    spent_total?: string | null;
}

/* -------------------------------------------------------------------------- */
/* Pagination                                                                  */
/* -------------------------------------------------------------------------- */

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

export type BreadcrumbItemType = BreadcrumbItem;
