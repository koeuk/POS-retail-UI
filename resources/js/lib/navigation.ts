import type { NavGroup, NavItem } from '@/types';
import {
    BookOpen,
    Boxes,
    ChartNoAxesColumn,
    HandCoins,
    History,
    LayoutGrid,
    MoreHorizontal,
    PackageSearch,
    ReceiptText,
    ScanBarcode,
    Shapes,
    Users,
    UsersRound,
    Utensils,
} from 'lucide-vue-next';

/**
 * One nav definition, two chromes. The desktop sidebar renders the groups and
 * the phone tab bar renders the first few items plus a "More" sheet — if the
 * two kept separate lists they would drift the first time a screen was added.
 */
export const navGroups: NavGroup[] = [
    {
        label: 'Selling',
        items: [
            { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
            { title: 'Point of Sale', href: '/pos', icon: ScanBarcode, requires: 'pos' },
            { title: 'Order History', href: '/orders', icon: ReceiptText, requires: 'orders' },
            { title: 'In Debt', href: '/debts', icon: HandCoins, requires: 'debts' },
            { title: 'Myself', href: '/consumption', icon: Utensils, requires: 'consumption' },
            { title: 'Reports', href: '/reports', icon: ChartNoAxesColumn, requires: 'reports' },
            // The public customer catalogue. No `requires`: a cashier may well
            // want to show someone the menu across the counter.
            { title: 'View Menu', href: '/menu', icon: BookOpen },
        ],
    },
    {
        label: 'Catalogue',
        items: [
            { title: 'Products', href: '/products', icon: Boxes, requires: 'products' },
            { title: 'Categories', href: '/categories', icon: Shapes, requires: 'categories' },
            { title: 'Inventory', href: '/inventory', icon: PackageSearch, requires: 'inventory' },
        ],
    },
    {
        label: 'People',
        items: [
            { title: 'Customers', href: '/customers', icon: UsersRound, requires: 'customers' },
            { title: 'Staff', href: '/users', icon: Users, requires: 'users' },
            { title: 'Activity Log', href: '/activity', icon: History, requires: 'activity' },
            /*
             * Stores is deliberately not in the nav: this is a single-store
             * shop, so the screen has nothing to choose between. The route is
             * still live at /stores — it remains the only place registers can
             * be added or renamed — so put it back here the day a second
             * location opens.
             */
        ],
    },
];

type Can = Record<string, boolean>;

const permitted = (item: NavItem, can: Can) => !item.requires || !!can[item.requires];

/** Groups the user can see something in, with the forbidden items removed. */
export function visibleGroups(can: Can): NavGroup[] {
    return navGroups
        .map((group) => ({ ...group, items: group.items.filter((item) => permitted(item, can)) }))
        .filter((group) => group.items.length > 0);
}

/** Flat list, group order preserved. */
export function visibleItems(can: Can): NavItem[] {
    return visibleGroups(can).flatMap((group) => group.items);
}

/**
 * The tab bar holds four slots. Three go to destinations and the fourth is
 * always "More" — a fixed last tab means the thumb learns one position, and
 * it is the only way the remaining screens stay reachable on a phone.
 *
 * A cashier sees only Dashboard and POS, so for them "More" is just the
 * account sheet and the bar is three tabs wide.
 */
export const TAB_SLOTS = 3;

export const moreTab = { title: 'More', href: '#more', icon: MoreHorizontal } as const;

export function tabItems(can: Can): NavItem[] {
    return visibleItems(can).slice(0, TAB_SLOTS);
}

/** Everything the tab bar could not fit — the contents of the More sheet. */
export function overflowItems(can: Can): NavItem[] {
    return visibleItems(can).slice(TAB_SLOTS);
}

/** `/products` must light up on `/products/4/edit`, but `/` must not swallow all. */
export function isActivePath(href: string, path: string): boolean {
    return path === href || path.startsWith(`${href}/`);
}
