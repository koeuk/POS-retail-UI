# POS Retail — Offline-First Cambodian Retail Management & POS

A modern, responsive, offline-first Point of Sale (POS) and retail management Single Page Application (SPA) designed specifically for retail shops, grocery stores, and mini-marts in **Cambodia**.

Built with **Vue 3**, **TypeScript**, **Tailwind CSS**, and **Vite** — running completely standalone with rich static mock data arrays.

---

## 1. Project Purpose & Vision

In Cambodia, retail businesses encounter distinct operational realities that off-the-shelf Western POS software cannot handle:

1. **Native Dual-Currency Support (USD & Khmer Riel ៛)**:
   - Stores in Cambodia transact interchangeably in **USD ($)** and **Khmer Riel (៛)** (default exchange rate: `1 USD = 4,100 KHR`).
   - Prices can be displayed and paid in either currency. Cashiers frequently receive USD and return change in Riel.
   - Riel has no sub-units/cents; values round to physical cash notes (100៛, 500៛, 1,000៛, 5,000៛, 10,000៛, etc.).

2. **Pack Size & Unit Hierarchy (Can / Six-Pack / Case)**:
   - A shop sells an individual beverage can, a 6-pack, or a 24-can case from the same physical item.
   - Pack sizes link directly to base unit quantities, ensuring accurate real-time inventory deductions without duplicating product SKUs.

3. **Customer Credit & Debt Tabs (សៀវភៅជំពាក់)**:
   - Neighborhood retail culture commonly relies on customer credit tabs.
   - The system tracks outstanding balances, deposit down-payments, partial repayments, and full order settlement receipts.

4. **Offline-First Till Operation**:
   - Unstable internet in local markets never stops sales.
   - The POS register saves transactions immediately into local IndexedDB (via Dexie), enabling smooth, uninterrupted scanning and checkout.

5. **Multi-Store & Per-Branch Inventory**:
   - Stock counts are tracked per store branch rather than a single global warehouse.

---

## 2. Tech Stack

- **Framework**: [Vue 3](https://vuejs.org/) (Composition API, `<script setup>`)
- **Language**: [TypeScript](https://www.typescriptlang.org/)
- **Build Tool**: [Vite 6](https://vitejs.dev/)
- **Styling**: [Tailwind CSS](https://tailwindcss.com/) with PostCSS & Autoprefixer
- **State Management**: [Pinia](https://pinia.vuejs.org/) (Cart and Register state)
- **Local Storage / Offline DB**: [Dexie.js](https://dexie.org/) (IndexedDB wrapper)
- **Component Primitives**: [Radix Vue](https://www.radix-vue.com/) / [Reka UI](https://reka-ui.com/), [Headless UI](https://headlessui.com/)
- **Icons**: [Lucide Icons](https://lucide.dev/)
- **HTTP / Mocking**: Client-side in-memory mock store with realistic Cambodian retail data

---

## 3. Key Feature Modules

| Module | Route | Key Functionality |
| :--- | :--- | :--- |
| **POS Register** | `/pos` | Fast touch/barcode till, product grid, cart calculations, KHQR & cash payment modal, offline queueing |
| **Dashboard** | `/dashboard` | Today vs yesterday sales, 7-day trend chart, low stock alerts, oversold items, recent orders |
| **Products** | `/products` | Product catalog, pack sizes (unit/six-pack/case), cost & sell pricing, barcode/SKU, category assignment |
| **Orders** | `/orders` | Complete order history, sale types (customer, debt, internal), invoice details, receipt printing |
| **Debt Tabs** | `/debts` | Customer credit tabs, outstanding balances, partial repayments, settlement flow |
| **Inventory** | `/inventory` | Per-store stock quantities, low-stock thresholds, stock adjustment history |
| **Customers** | `/customers` | Customer profiles, phone numbers, total spend, loyalty points |
| **Stores & Registers** | `/stores` | Store branches, active registers / cash counters |
| **Vendors** | `/vendors` | Supplier directory, contact information, supplied items |
| **Reports** | `/reports` | Revenue metrics, sales by category, sales by payment method, exportable summaries |
| **Public Menu** | `/menu` | Public-facing digital product catalog accessible via QR code |
| **Shop Settings** | `/settings` | Shop profile, dual-currency exchange rate, KHQR payment configuration, theme toggle (Light / Dark) |

---

## 4. Project Structure

```
POS-retail-UI/
├── index.html              # Standalone SPA HTML entry point
├── vite.config.ts          # Vite configuration with Vue plugin & path aliases
├── tailwind.config.js      # Custom theme colors, typography & layout styling
├── package.json            # Scripts & dependencies
├── README.md               # Unified project documentation
├── public/                 # Static brand assets (logo, favicon)
│   ├── favicon.ico
│   └── logo.svg
└── resources/
    ├── css/
    │   └── app.css         # Tailwind directives & CSS design tokens
    └── js/
        ├── app.ts          # App bootstrap, Pinia setup, plugins
        ├── components/     # UI widgets (AppSidebar, AppHeader, Modals, Tables, Charts)
        ├── composables/    # Reusable hooks (useCurrency, usePermissions, useAppearance)
        ├── layouts/        # AppLayout, SettingsLayout, AuthSimpleLayout
        ├── mock/           # Mock data arrays & client-side routing adapter
        │   ├── data.ts     # Realistic Cambodian retail mock arrays (Products, Orders, etc.)
        │   ├── inertia.ts  # Client-side SPA navigation & reactive state manager
        │   └── api.ts      # Offline POS sync & fetch interceptors
        ├── pages/          # Full page views (Dashboard, Pos, Products, Orders, etc.)
        ├── Pos/            # Specialized POS till components, barcode scanner, cart
        └── types/          # TypeScript domain models & interfaces
```

---

## 5. Getting Started

### Prerequisites
- **Node.js**: `20.x` or `22.x`
- **npm**: `10.x` or later

### Installation & Execution

```bash
# 1. Install frontend dependencies
npm install

# 2. Launch the local development server (with Hot Module Replacement)
npm run dev

# 3. Open in your browser
http://localhost:5173
```

### Production Build

```bash
# Build optimized static assets for production
npm run build

# Preview the production build locally
npm run preview
```

---

## 6. License

This project is licensed under the Apache-2.0 License.
