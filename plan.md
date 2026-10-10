# POS Retail — Project Purpose & Execution Plan (`plan.md`)

## 1. Project Purpose & Vision

**POS Retail** is an offline-first Point of Sale (POS) and retail management Single Page Application (SPA) designed specifically for retail shops, grocery stores, and mini-marts in **Cambodia**.

The project is built as a modern, high-performance, responsive web application using **Vue 3, TypeScript, Tailwind CSS, and Vite**, operating 100% standalone on the frontend with rich static mock data arrays — eliminating backend dependencies while retaining a complete, interactive user experience.

---

## 2. Core Market Problems Solved

1. **Native Dual-Currency Support (USD & Khmer Riel ៛)**:
   - Retail businesses in Cambodia handle both **USD ($)** and **Khmer Riel (៛)** interchangeably (standard default rate: `1 USD = 4,100 KHR`).
   - Prices, carts, checkout tenders, and change calculations support both currencies seamlessly.
   - Riel has no decimal cents; cash change rounds to standard circulating banknotes (100៛, 500៛, 1,000៛, 5,000៛, 10,000៛).

2. **Pack Size & Unit Hierarchy (Can / 6-Pack / Case)**:
   - Retailers sell single cans, 6-packs, or 24-can cases from the same stock item.
   - Pack sizes link directly to base units, ensuring that selling a 24-can case automatically decrements 24 base units from real-time store stock.

3. **Customer Credit & Debt Tabs (សៀវភៅជំពាក់)**:
   - Neighborhood retail culture in Cambodia relies on customer credit tabs.
   - The application manages open debt tabs, deposit down-payments, partial repayments, and full order settlement receipts.

4. **Offline-First Till Operation**:
   - The POS till registers transactions directly in local browser storage (via IndexedDB and Dexie), ensuring rapid barcode scanning and checkout without internet interruptions.

5. **Multi-Store & Per-Branch Inventory**:
   - Stock counts are tracked per store branch rather than a single global warehouse.

---

## 3. Technology Stack

- **Frontend Framework**: Vue 3 (Composition API, `<script setup>`)
- **Language**: TypeScript (strict typing for all domain models)
- **Styling**: Tailwind CSS with PostCSS & Autoprefixer
- **Build Tool**: Vite 6 (Standalone SPA)
- **State Management**: Pinia (POS Cart and Till session)
- **Offline Database**: Dexie.js (IndexedDB wrapper)
- **UI Primitives**: Radix Vue / Reka UI, Headless UI
- **Icons**: Lucide Icons
- **Mock Data Engine**: In-memory mock arrays & client-side routing adapter (`resources/js/mock/`)

---

## 4. Implementation Roadmap & Status

### Phase 1: Backend Removal & Standalone SPA Setup
- [x] **Backend Removal**: Permanently deleted all PHP/Laravel files (`app/`, `database/`, `routes/`, `config/`, `bootstrap/`, `tests/`, `storage/`, `artisan`, `composer.*`).
- [x] **Package Configuration**: Updated `package.json` to remove Laravel plugins while keeping Vue 3, Pinia, Dexie, Tailwind CSS, and Lucide icons.
- [x] **Dependencies Installation**: Successfully ran `npm install` (379 packages installed).
- [x] **SPA Entry Point**: Created root `index.html` mounting `#app` with modern typography (Instrument Sans & JetBrains Mono).
- [x] **Vite Setup**: Configured `vite.config.ts` for standalone Vue SPA with path alias `@` and `@inertiajs/vue3` mock provider.

### Phase 2: Mock Data Arrays & Storage Layer
- [x] **Mock Data Arrays (`resources/js/mock/data.ts`)**:
  - Dual-currency shop settings (`USD` & `KHR` @ 4,100 rate)
  - Products catalog with pack hierarchies (Can, 6-Pack, Case), cost/sell prices, SKUs, and barcodes
  - Categories (Beverages, Alcohol, Snacks, Groceries, Dairy, Personal Care)
  - Store branches (Main BKK1, Tuol Kork) with physical cash registers
  - Per-store inventory levels, low stock warnings, and oversold flags
  - Customers directory with spend history and loyalty points
  - Orders history with item breakdowns, payment methods (Cash, Bakong KHQR, Debt)
  - Customer debt tabs with outstanding balances and repayment logs
  - Dashboard analytics (sales trends, average basket, today vs yesterday)
- [x] **API Mock Interceptors (`resources/js/mock/api.ts`)**:
  - Intercept Axios requests for POS till (`/pos/data/products`, `/pos/data/customers`, `/pos/data/orders/sync`, `/pos/data/heartbeat`)
  - Intercept Fetch requests for customer search and debt product lookup

### Phase 3: Client-Side Routing & Reactive UI Adapter
- [x] **Inertia Compatibility Layer (`resources/js/mock/inertia.ts`)**:
  - `createInertiaApp`: Initializes Vue app, Pinia, Ziggy routes, and mounts the SPA.
  - `router`: Client-side navigation (`visit`, `get`, `post`, `put`, `delete`) with browser history support.
  - `usePage`: Reactive state provider supplying `auth`, `currency`, `branding`, `flash`, and current page props.
  - `useForm`: Reactive form helper with dirty checking, validation states, and in-memory mutations.
  - `<Link>` & `<Head>`: Seamless client-side navigation links and document title management.
- [x] **App Bootstrap (`resources/js/app.ts`)**:
  - Initialized mock API interceptors.
  - Registered `ZiggyVue` and `createPinia()`.
  - Configured dark/light theme initialization.

### Phase 4: Build Verification & Testing
- [ ] **Permissions & Build Fix**: Resolve file write permissions on `node_modules/.vite-temp` and run `npm run build`.
- [ ] **Dev Server Verification**: Run `npm run dev` and test interactive navigation across all routes:
  - `/dashboard` (Executive KPI metrics & charts)
  - `/pos` (Cashier till, barcode scanning, cart & payment modals)
  - `/products` (Catalog list, filtering, add/edit modals)
  - `/orders` (Sales history, receipt view & printing)
  - `/debts` (Customer credit ledger & debt settlement)
  - `/inventory` (Store stock levels & replenishment)
  - `/customers` (Customer profiles & loyalty)
  - `/stores` (Store branches & registers)
  - `/reports` (Sales breakdown & analytics)
  - `/settings/*` (Shop profile, currency, payments, appearance)

---

## 5. Quickstart Commands

```bash
# Install frontend dependencies
npm install

# Start local development server (http://localhost:5173)
npm run dev

# Build production bundle
npm run build

# Preview production build locally
npm run preview
```
