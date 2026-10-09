# Implementation Plan: Phases 2–4 — Standalone SPA Migration

This plan completes the migration to a standalone SPA by refining the mock API interceptors, implementing a complete Inertia compatibility layer with named route support, and updating the app entry point.

---

## Phase 2: Refine Mock API Interceptors

- [ ] 1. **Enhance `/resources/js/mock/api.ts` with realistic network delays and complete endpoint coverage**
      
      The existing mock API interceptors are functional but need enhancements:
      - Increase the simulated delay range to 50–200ms for more realistic responsiveness (currently hardcoded to 80ms)
      - Verify all POS endpoints return correct data shapes matching `PosFeed`, `SyncResult[]`, and customer list
      - Ensure the Fetch interceptor for `/debts/product-lookup` and `/pos/data/customers` returns proper JSON responses
      - Add error simulation for edge cases (e.g., 10% chance of sync failure for testing error handling)
      
      **Files:**
      - `/mnt/DriveBeltie/Projects/POS-retail-UI/resources/js/mock/api.ts`
      
      **Verify:**
      - Open browser DevTools Network tab
      - Navigate to `/pos` and scan a barcode
      - Confirm requests to `/pos/data/products` and `/pos/data/heartbeat` return 200 with correct mock data
      - Check response timing shows 50–200ms delays
      - Test order sync by completing a sale and verifying `/pos/data/orders/sync` returns `SyncResult[]` with acknowledgment

---

## Phase 3: Complete Inertia Compatibility Layer

- [ ] 2. **Implement the `route()` helper function in `/resources/js/mock/inertia.ts`**
      
      Many components call `route('dashboard')`, `route('products.index')`, etc., but this helper is missing. The route() function must:
      - Accept a route name (string) and optional params object
      - Return the corresponding URL string
      - Support the named routes documented in PLAN.md and used throughout components:
        - `dashboard` → `/dashboard`
        - `pos.index` → `/pos`
        - `products.index` → `/products`
        - `products.create` → `/products/create`
        - `products.show` → `/products/{uuid}`
        - `products.edit` → `/products/{uuid}/edit`
        - `orders.index` → `/orders`
        - `orders.show` → `/orders/{uuid}`
        - `debts.index` → `/debts`
        - `inventory.index` → `/inventory`
        - `categories.index` → `/categories`
        - `customers.index` → `/customers`
        - `stores.index` → `/stores`
        - `users.index` → `/users`
        - `vendors.index` → `/vendors`
        - `vendors.show` → `/vendors/{uuid}`
        - `reports.index` → `/reports`
        - `consumption.index` → `/consumption`
        - `menu.index` → `/menu`
        - `activity.index` → `/activity`
        - `settings.shop` → `/settings/shop`
        - `profile.edit` → `/settings/profile`
        - `settings.password` → `/settings/password`
        - `settings.payments` → `/settings/payments`
        - `settings.appearance` → `/settings/appearance`
        - `logout` → `/logout`
        - `login` → `/login`
        - `password.request` → `/forgot-password`
        - `password.email` → `/password/email`
        - `password.store` → `/password/reset`
        - `password.confirm` → `/user/confirm-password`
        - `verification.send` → `/email/verification-notification`
        - `home` → `/`
      - Handle parameterized routes by replacing `{param}` with `params[key]` values
      - Export the function so it's available globally (components use it without importing)
      
      **Files:**
      - `/mnt/DriveBeltie/Projects/POS-retail-UI/resources/js/mock/inertia.ts`
      
      **Implementation details:**
      Create a route map object with all named routes, then implement:
      ```typescript
      const routeMap: Record<string, string> = {
        'dashboard': '/dashboard',
        'pos.index': '/pos',
        'products.index': '/products',
        'products.create': '/products/create',
        'products.show': '/products/{product}',
        'products.edit': '/products/{product}/edit',
        // ... all routes above
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
      ```
      
      Make `route` globally available by attaching to window:
      ```typescript
      if (typeof window !== 'undefined') {
        (window as any).route = route;
      }
      ```
      
      **Verify:**
      - Open browser console
      - Type `route('dashboard')` and confirm it returns `/dashboard`
      - Type `route('products.show', {product: 'prd-123'})` and confirm it returns `/products/prd-123`
      - Navigate through the app and verify all navigation links work (sidebar, breadcrumbs, buttons)

- [ ] 3. **Add dynamic page routing props for all 33 page components**
      
      The `resolveRouteProps()` function in mock/inertia.ts handles some pages but is missing many. Review the complete list of page components:
      - Activity/Index.vue, Activity/Show.vue
      - auth/* (Login, ForgotPassword, ResetPassword, ConfirmPassword, VerifyEmail, VerifyOtp)
      - Categories/Index.vue
      - Consumption/Index.vue
      - Customers/Index.vue
      - Dashboard.vue
      - Debts/Index.vue
      - Inventory/Index.vue
      - Menu/Index.vue, Menu/Show.vue
      - Orders/Index.vue, Orders/Show.vue
      - Pos/Index.vue
      - Products/Create.vue, Products/Edit.vue, Products/Index.vue, Products/Show.vue
      - Reports/Index.vue
      - settings/* (Appearance, Password, Payments, Profile, Shop)
      - Stores/Index.vue
      - Users/Index.vue
      - Vendors/Index.vue, Vendors/Show.vue
      
      For each page, ensure `resolveRouteProps()` returns the correct shape from mock data. Pages already implemented: Dashboard, Pos/Index, Products/*, Orders/*, Debts/Index, Inventory/Index, Categories/Index, Customers/Index, Stores/Index, Users/Index, Vendors/*, Reports/Index, Consumption/Index, Menu/Index, Activity/Index, settings/*.
      
      Missing cases to implement:
      - **Activity/Show.vue**: Extract activity ID from path, return single activity record with details
      - **Menu/Show.vue**: Extract product UUID from path, return product detail with category
      - **auth pages**: Return appropriate props (status, errors, canResetPassword for Login)
      
      Add cases in `resolveRouteProps()` for Activity/Show and Menu/Show, and stub cases for auth pages that return empty objects (auth pages are not the focus of the standalone demo).
      
      Also ensure `matchPathToComponent()` maps all URL patterns correctly to component names. Currently missing:
      - `/activity/{uuid}` → `Activity/Show`
      - `/menu/{uuid}` → `Menu/Show`
      
      **Files:**
      - `/mnt/DriveBeltie/Projects/POS-retail-UI/resources/js/mock/inertia.ts`
      
      **Verify:**
      - Run `npm run dev`
      - Navigate to each page in the navigation menu
      - Verify page loads without console errors
      - Check that props data displays correctly (e.g., products list, orders history, dashboard stats)

- [ ] 4. **Ensure Link component handles external URLs correctly**
      
      The current Link component in mock/inertia.ts prevents default and calls router.visit for all hrefs. This breaks external links (e.g., documentation links). Update the Link component's onClick handler to:
      - Check if href starts with `http://` or `https://` or `mailto:` — if so, allow default behavior
      - Check if href starts with `#` (anchor links) — allow default behavior
      - Otherwise, prevent default and call router.visit
      
      **Files:**
      - `/mnt/DriveBeltie/Projects/POS-retail-UI/resources/js/mock/inertia.ts`
      
      **Verify:**
      - Add a test link `<a href="https://google.com" target="_blank">External</a>` to Dashboard
      - Click it and verify it opens Google in a new tab instead of client-side navigation
      - Remove the test link after verification

---

## Phase 4: Update App Entry Point

- [ ] 5. **Update `/resources/js/app.ts` to import from mock/inertia instead of @inertiajs/vue3**
      
      The current app.ts imports `createInertiaApp` and `resolvePageComponent` from `@inertiajs/vue3`. Since vite.config.ts already aliases `@inertiajs/vue3` to `./resources/js/mock/inertia.ts`, this should work automatically. However, verify:
      - The imports resolve correctly
      - `ZiggyVue` and `Ziggy` imports are removed (ziggy.ts doesn't exist, and route() is now in mock/inertia.ts)
      - The mock API is set up before createInertiaApp is called (already done via setupMockApi())
      - Document title formatting works via the title callback
      
      Remove these lines from app.ts:
      ```typescript
      import { ZiggyVue } from 'ziggy-js';
      import { Ziggy } from './ziggy';
      ```
      
      And remove from the app setup:
      ```typescript
      .use(ZiggyVue, Ziggy)
      ```
      
      The route() function is now globally available from mock/inertia.ts, so components don't need ZiggyVue.
      
      **Files:**
      - `/mnt/DriveBeltie/Projects/POS-retail-UI/resources/js/app.ts`
      
      **Verify:**
      - Run `npm run build`
      - Confirm build succeeds with no errors
      - Check that the dist/ output contains app bundle
      - Run `npm run dev` and navigate to `/dashboard`
      - Open browser console and verify no errors about missing modules or undefined route function

- [ ] 6. **Remove laravel-vite-plugin references if present**
      
      The current vite.config.ts does NOT import laravel-vite-plugin (already removed in Phase 1), but double-check that no references remain in:
      - vite.config.ts
      - package.json dependencies
      
      If found, remove the import and plugin usage. The current vite.config.ts is clean — just verify and document that no changes are needed.
      
      **Files:**
      - `/mnt/DriveBeltie/Projects/POS-retail-UI/vite.config.ts`
      - `/mnt/DriveBeltie/Projects/POS-retail-UI/package.json`
      
      **Verify:**
      - `cat vite.config.ts | grep -i laravel` returns no results
      - `cat package.json | grep -i laravel` returns no results
      - Run `npm run build` successfully

---

## Phase 5: Integration Testing

- [ ] 7. **End-to-end navigation and interaction testing**
      
      Test all primary user flows across the standalone SPA:
      
      **Dashboard:**
      - Navigate to `/dashboard`
      - Verify KPI cards display mock data (today sales, orders, basket, items)
      - Verify trend chart renders
      - Verify low stock alerts show
      - Verify recent orders list displays
      
      **POS Till:**
      - Navigate to `/pos`
      - Scan a barcode (type in search field)
      - Add product to cart
      - Open payment modal
      - Complete a cash sale
      - Verify receipt preview displays
      - Check that order syncs (mock interceptor returns SyncResult)
      
      **Products:**
      - Navigate to `/products`
      - Verify product list displays with pagination
      - Filter by category
      - Search for a product by name/SKU
      - Click product to view detail page
      - Click Edit button
      - Verify product edit form loads
      
      **Orders:**
      - Navigate to `/orders`
      - Verify orders list displays
      - Click an order to view detail
      - Verify order items, payments, and receipt display
      
      **Debts:**
      - Navigate to `/debts`
      - Verify debt list displays
      - Click settle button
      - Enter payment amount
      - Submit and verify flash success message
      - Verify debt balance updates in UI
      
      **Inventory:**
      - Navigate to `/inventory`
      - Verify stock levels display per store
      - Check low stock warnings are highlighted
      
      **Settings:**
      - Navigate to `/settings/shop`
      - Modify shop name
      - Submit form and verify flash success message
      - Navigate to `/settings/appearance`
      - Toggle dark/light theme
      - Verify theme persists on page reload
      
      **Navigation:**
      - Use sidebar navigation to move between pages
      - Click browser back button and verify navigation works
      - Use mobile responsive view and test tab bar navigation
      - Verify all named routes resolve correctly
      
      **Files:**
      - All files in `/mnt/DriveBeltie/Projects/POS-retail-UI/resources/js/pages/`
      
      **Verify:**
      - No console errors during any user flow
      - No network errors (all requests return 200 from mock interceptors)
      - All page transitions are smooth (50–200ms delay feels responsive)
      - Flash messages display after form submissions
      - Browser back/forward buttons work correctly
      - Document title updates on each page navigation
      - Theme toggle persists across navigation

---

## Summary

**Total Items:** 7

**Key Files Modified:**
1. `/mnt/DriveBeltie/Projects/POS-retail-UI/resources/js/mock/api.ts` — Enhanced interceptors with realistic delays
2. `/mnt/DriveBeltie/Projects/POS-retail-UI/resources/js/mock/inertia.ts` — Added route() helper, completed page routing, fixed external links
3. `/mnt/DriveBeltie/Projects/POS-retail-UI/resources/js/app.ts` — Removed Ziggy dependencies

**Build Commands:**
- `npm run dev` — Start development server at http://localhost:5173
- `npm run build` — Build production bundle to dist/
- `npm run preview` — Preview production build

**Verification Strategy:**
Each item includes a specific verification step using either:
- Browser DevTools Network tab (for API mocks)
- Browser console (for route() function)
- Manual navigation testing (for page routing)
- Build command success (for app.ts changes)

**Success Criteria:**
- All 33 page components load without errors
- All navigation links resolve correctly via route() helper
- All POS operations work offline with mock data
- Browser back/forward navigation works
- Theme toggle persists
- No console errors or 404s in network tab
