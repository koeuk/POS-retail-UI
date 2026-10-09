<?php

namespace Database\Seeders;

use App\Enums\InventoryLogType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\SaleType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Register;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Support\Currency;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A whole pretend shop for trying the app out: shelf, staff, customers, and
 * two months of trading behind it. Never run by default — a real shop starts
 * empty and enters its own catalogue.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Everything here obeys the same invariants the app enforces, because demo
 * data that disagrees with the reports is worse than no demo data at all:
 *
 *   - total = subtotal - discount_amount, and paid_amount = SUM(payments)
 *   - `myself` sales move stock but are NOT revenue
 *   - a debt is owed BY a named customer and is never paid in full at the till
 *   - change is only ever given against cash
 *   - a pack sale takes units_per_pack off the base product's shelf
 *   - order_no is per store per business day, in the shop's own timezone
 *   - every stock movement leaves an inventory_logs row, with a causer
 */
class DemoSeeder extends Seeder
{
    /** How far back the trading history runs. */
    private const DAYS = 60;

    private Store $store;

    private Register $register;

    /** @var array<int, User> Who might have rung a sale up. */
    private array $cashiers = [];

    /** Per-store running stock, so movements and the stocks table agree. */
    private array $onHand = [];

    /** Sequence per "{storeId}:{Ymd}", mirroring OrderSyncService. */
    private array $orderSeq = [];

    public function run(): void
    {
        $this->store = Store::firstOrFail();
        $this->register = Register::firstOrCreate(
            ['store_id' => $this->store->id, 'name' => 'Register 1'],
            ['is_active' => true],
        );

        $products = $this->seedProducts($this->seedCategories());
        $this->seedStaff();
        $customers = $this->seedCustomers();

        // Opening stock first: every later sale draws down from a real number,
        // so nothing goes negative for a reason the ledger cannot explain.
        $this->seedOpeningStock($products);
        $this->seedTrading($products, $customers);

        $this->command?->info(sprintf(
            'Demo shop ready: %d products, %d customers, %d orders.',
            count($products),
            count($customers),
            Order::count(),
        ));
    }

    /** @return array<string, Category> */
    private function seedCategories(): array
    {
        $flat = [];

        foreach ([
            'ស្រាបៀរ (Beer)',
            'ភេសជ្ជៈ (Drinks)',
            'ទឹកសុទ្ធ (Water)',
            'មី និង បាយ (Noodles & Rice)',
            'ត្រី និង សាច់ (Fish & Meat)',
            'នំ (Snacks)',
            'គ្រឿងផ្សំ (Seasoning)',
            'កាហ្វេ និង តែ (Coffee & Tea)',
            'សម្អាត (Cleaning)',
            'ប្រើប្រាស់ផ្ទាល់ខ្លួន (Personal Care)',
        ] as $name) {
            $flat[$name] = Category::create(['name' => $name]);
        }

        return $flat;
    }

    /**
     * The shelf, plus the pack sizes that make this a Cambodian shop rather
     * than a spreadsheet: a can, a six-pack of the same can, and a case of 24.
     * A pack is its own product row pointing at the base one — the pack has no
     * shelf of its own, so selling one draws units_per_pack off the parent.
     *
     * @param  array<string, Category>  $categories
     * @return array<int, Product>
     */
    private function seedProducts(array $categories): array
    {
        // [name, category, cost, sell, unit, packs[]]
        // packs: [suffix, units_per_pack, cost, sell, unit]
        /*
         * Prices are riel, stored exactly as a Cambodian shelf tag reads —
         * the shop's currency is KHR (see DatabaseSeeder), so 2500 IS ២៥០០៛.
         *
         * Every beer sells in four tiers: the can, six, twelve, and the full
         * case of 24 — each tier its own product row drawing off the base
         * can's shelf.
         */
        $beerTiers = fn (int $costCan) => [
            ['៦ កំប៉ុង', 6, $costCan * 6 - 400, 14500, 'កញ្ចប់'],
            ['១២ កំប៉ុង', 12, $costCan * 12 - 1300, 28000, 'កញ្ចប់'],
            ['កេស ២៤', 24, $costCan * 24 - 3600, 55000, 'កេស'],
        ];

        $catalogue = [
            // ស្រាបៀរ — ១កំប៉ុង 2500៛, ៦ 14,500៛, ១២ 28,000៛, ២៤ 55,000៛
            ['ស្រាបៀរ អង្គរ (Angkor) 330ml', 'ស្រាបៀរ (Beer)', 1900, 2500, 'កំប៉ុង', $beerTiers(1900)],
            ['ស្រាបៀរ កម្ពុជា (Cambodia) 330ml', 'ស្រាបៀរ (Beer)', 1900, 2500, 'កំប៉ុង', $beerTiers(1900)],
            ['ស្រាបៀរ ហនុមាន (Hanuman) 330ml', 'ស្រាបៀរ (Beer)', 1950, 2500, 'កំប៉ុង', $beerTiers(1950)],
            ['ស្រាបៀរ ហ្គាន់ស្បឺក (Ganzberg) 330ml', 'ស្រាបៀរ (Beer)', 1950, 2500, 'កំប៉ុង', $beerTiers(1950)],
            ['ស្រាបៀរ គ្រុត (Krud) 330ml', 'ស្រាបៀរ (Beer)', 1950, 2500, 'កំប៉ុង', $beerTiers(1950)],
            ['ស្រាបៀរ ដ្រាហ្គន (Dragon) 330ml', 'ស្រាបៀរ (Beer)', 1850, 2500, 'កំប៉ុង', $beerTiers(1850)],

            // ភេសជ្ជៈ
            ['កូកា កូឡា (Coca-Cola) 330ml', 'ភេសជ្ជៈ (Drinks)', 1800, 2500, 'កំប៉ុង', [
                ['៦ កំប៉ុង', 6, 10200, 14000, 'កញ្ចប់'],
            ]],
            ['ស្ព្រាយ (Sprite) 330ml', 'ភេសជ្ជៈ (Drinks)', 1800, 2500, 'កំប៉ុង', []],
            ['ភេសជ្ជៈ ស្ទីង (Sting) 330ml', 'ភេសជ្ជៈ (Drinks)', 1400, 2000, 'ដប', []],
            ['ទឹកក្រូច (Orange Juice) 250ml', 'ភេសជ្ជៈ (Drinks)', 1900, 2800, 'ប្រអប់', []],

            // ទឹកសុទ្ធ
            ['ទឹកសុទ្ធ វិត្តាល់ (Vital) 500ml', 'ទឹកសុទ្ធ (Water)', 600, 1000, 'ដប', [
                ['កេស ២៤', 24, 13000, 22000, 'កេស'],
            ]],
            ['ទឹកសុទ្ធ វិត្តាល់ (Vital) 1.5L', 'ទឹកសុទ្ធ (Water)', 1500, 2500, 'ដប', []],
            ['ទឹកសុទ្ធ គីរីរម្យ (Kirirom) 500ml', 'ទឹកសុទ្ធ (Water)', 550, 1000, 'ដប', [
                ['កេស ២៤', 24, 12000, 20000, 'កេស'],
            ]],

            // មី និង បាយ
            ['មីកញ្ចប់ ម៉ាម៉ា (MAMA) សាច់ជ្រូក', 'មី និង បាយ (Noodles & Rice)', 1300, 2000, 'កញ្ចប់', [
                ['ថង់ ៣០', 30, 36000, 55000, 'ថង់'],
            ]],
            ['មីកញ្ចប់ ម៉ាម៉ា (MAMA) ត្រី', 'មី និង បាយ (Noodles & Rice)', 1300, 2000, 'កញ្ចប់', []],
            ['មីកញ្ចប់ យ៉ាំយ៉ាំ (YumYum)', 'មី និង បាយ (Noodles & Rice)', 1200, 1800, 'កញ្ចប់', [
                ['ថង់ ៣០', 30, 33000, 50000, 'ថង់'],
            ]],
            ['អង្ករ ផ្កាម្លិះ (Jasmine Rice)', 'មី និង បាយ (Noodles & Rice)', 3800, 5500, 'គីឡូ', [
                ['បាវ ៥០គីឡូ', 50, 180000, 260000, 'បាវ'],
            ]],
            ['មីស៊ុប (Instant Porridge)', 'មី និង បាយ (Noodles & Rice)', 1500, 2200, 'កញ្ចប់', []],

            // ត្រី និង សាច់
            ['ត្រីខ (Trey Kho) កំប៉ុង', 'ត្រី និង សាច់ (Fish & Meat)', 4500, 7000, 'កំប៉ុង', []],
            ['ត្រីខ ឆាការី (Fried Fish) កំប៉ុង', 'ត្រី និង សាច់ (Fish & Meat)', 4800, 7500, 'កំប៉ុង', []],
            ['ប្រហុក (Prahok) កំប៉ុង', 'ត្រី និង សាច់ (Fish & Meat)', 5800, 9000, 'កំប៉ុង', []],
            ['សាច់ជ្រូកកំប៉ុង (Canned Pork)', 'ត្រី និង សាច់ (Fish & Meat)', 6500, 10000, 'កំប៉ុង', []],
            ['ស៊ុត (Eggs)', 'ត្រី និង សាច់ (Fish & Meat)', 800, 1200, 'គ្រាប់', [
                ['ថាស ៣០', 30, 21000, 33000, 'ថាស'],
            ]],

            // នំ
            ['នំកញ្ចប់ បន្ទះដំឡូង (Potato Chips)', 'នំ (Snacks)', 4800, 7500, 'កញ្ចប់', []],
            ['នំកញ្ចប់ ខ្ទឹម (Prawn Crackers)', 'នំ (Snacks)', 3800, 6000, 'កញ្ចប់', []],
            ['នំប៉័ង (Bread)', 'នំ (Snacks)', 1800, 3000, 'ដុំ', []],
            ['នំខូឃី សូកូឡា (Chocolate Biscuits)', 'នំ (Snacks)', 6800, 10500, 'កញ្ចប់', []],
            ['នំអូរីអូ (Oreo)', 'នំ (Snacks)', 2600, 4000, 'កញ្ចប់', []],

            // គ្រឿងផ្សំ
            ['ទឹកត្រី (Fish Sauce) 500ml', 'គ្រឿងផ្សំ (Seasoning)', 3800, 6000, 'ដប', []],
            ['ទឹកស៊ីអ៊ីវ (Soy Sauce) 500ml', 'គ្រឿងផ្សំ (Seasoning)', 3500, 5500, 'ដប', []],
            ['ស្ករស (Sugar)', 'គ្រឿងផ្សំ (Seasoning)', 3000, 4500, 'គីឡូ', []],
            ['អំបិល (Salt)', 'គ្រឿងផ្សំ (Seasoning)', 1200, 2000, 'កញ្ចប់', []],
            ['ប្រេងឆា (Cooking Oil) 1L', 'គ្រឿងផ្សំ (Seasoning)', 8500, 12000, 'ដប', []],

            // កាហ្វេ និង តែ
            ['កាហ្វេ ណេស្កាហ្វេ (Nescafé) 3in1', 'កាហ្វេ និង តែ (Coffee & Tea)', 700, 1200, 'កញ្ចប់', [
                ['ប្រអប់ ៣០', 30, 20000, 32000, 'ប្រអប់'],
            ]],
            ['កាហ្វេខ្មែរ (Khmer Coffee) 200g', 'កាហ្វេ និង តែ (Coffee & Tea)', 7500, 12000, 'កញ្ចប់', []],
            ['តែបៃតង (Green Tea) 25 bags', 'កាហ្វេ និង តែ (Coffee & Tea)', 8500, 13000, 'ប្រអប់', []],

            // សម្អាត
            ['សាប៊ូលាងចាន (Dish Soap) 500ml', 'សម្អាត (Cleaning)', 7000, 11000, 'ដប', []],
            ['ម្សៅបោកខោអាវ (Detergent) 1kg', 'សម្អាត (Cleaning)', 7500, 11500, 'កញ្ចប់', []],
            ['ក្រដាសអនាម័យ (Toilet Paper) 4 rolls', 'សម្អាត (Cleaning)', 8500, 13000, 'កញ្ចប់', []],

            // ប្រើប្រាស់ផ្ទាល់ខ្លួន
            ['សាប៊ូដុំ (Bar Soap) 100g', 'ប្រើប្រាស់ផ្ទាល់ខ្លួន (Personal Care)', 2500, 4000, 'ដុំ', []],
            ['សាប៊ូកក់សក់ (Shampoo) 400ml', 'ប្រើប្រាស់ផ្ទាល់ខ្លួន (Personal Care)', 14000, 21000, 'ដប', []],
            ['ថ្នាំដុសធ្មេញ (Toothpaste) 120g', 'ប្រើប្រាស់ផ្ទាល់ខ្លួន (Personal Care)', 6500, 10000, 'ដប', []],
        ];

        $seq = 0;
        $all = [];

        foreach ($catalogue as [$name, $categoryName, $cost, $sell, $unit, $packs]) {
            $sku = sprintf('SKU-%04d', ++$seq);

            $base = Product::create([
                'category_id' => $categories[$categoryName]->id,
                'name' => $name,
                'sku' => $sku,
                'barcode' => sprintf('88510000%04d', $seq),
                'cost_price' => $cost,
                'sell_price' => $sell,
                'unit' => $unit,
                'units_per_pack' => 1,
                'case_size' => $packs ? end($packs)[1] : null,
                // By link, exactly as the product form's URL fields save them:
                // an http(s) source is stored as-is and rendered directly,
                // never copied into /storage. Seeded picsum URLs are stable
                // per SKU, so the same product keeps the same face.
                'image' => self::REAL_IMAGES[$name] ?? $this->imageLink($sku),
                'gallery' => [
                    $this->imageLink($sku, 'g1'),
                    $this->imageLink($sku, 'g2'),
                    $this->imageLink($sku, 'g3'),
                ],
                'track_stock' => true,
                'is_active' => true,
            ]);

            $all[] = $base;

            foreach ($packs as [$suffix, $per, $packCost, $packSell, $packUnit]) {
                // A pack does NOT track its own stock — the base product's
                // shelf is the only shelf. track_stock false is what stops the
                // inventory screen offering a second, phantom quantity.
                $all[] = Product::create([
                    'category_id' => $categories[$categoryName]->id,
                    'parent_product_id' => $base->id,
                    'name' => "{$name} ({$suffix})",
                    'sku' => sprintf('SKU-%04d', ++$seq),
                    'barcode' => sprintf('88510000%04d', $seq),
                    'cost_price' => $packCost,
                    'sell_price' => $packSell,
                    'unit' => $packUnit,
                    'units_per_pack' => $per,
                    // The pack wears the base product's face — it is the same
                    // goods in a bigger box, and the POS grid reads better
                    // when the crate looks like what is inside it.
                    'image' => $base->image,
                    'track_stock' => false,
                    'is_active' => true,
                ]);
            }
        }

        return $all;
    }

    /**
     * Staff beyond the four DatabaseSeeder makes, each carrying a different
     * permission matrix — so the Staff screen has something to show and the
     * per-action gates have something to prove.
     */
    private function seedStaff(): void
    {
        $accounts = [
            [
                'name' => 'សុខា (Sokha) — Senior cashier',
                'email' => 'sokha@gmail.com',
                'role' => Role::Cashier,
                'store_id' => $this->store->id,
                // Trusted at the till and allowed to read the numbers, but
                // never to change the catalogue.
                'permissions' => [
                    Permission::Pos->value => $this->actions(true),
                    Permission::Reports->value => $this->actions(view: true),
                    Permission::Orders->value => $this->actions(view: true),
                    Permission::Products->value => $this->actions(view: true),
                ],
            ],
            [
                'name' => 'ដារ៉ា (Dara) — Stock manager',
                'email' => 'dara@gmail.com',
                'role' => Role::Manager,
                'store_id' => $this->store->id,
                // Runs the shelf: may add and correct, but may not delete —
                // the case the per-action matrix exists for.
                'permissions' => [
                    Permission::Products->value => $this->actions(view: true, create: true, update: true),
                    Permission::Categories->value => $this->actions(view: true, create: true, update: true),
                    Permission::Inventory->value => $this->actions(true),
                    Permission::Reports->value => $this->actions(view: true),
                ],
            ],
            [
                'name' => 'បុប្ផា (Bopha) — Weekend cashier',
                'email' => 'bopha@gmail.com',
                'role' => Role::Cashier,
                'store_id' => $this->store->id,
                // The plainest account there is: the till and nothing else.
                'permissions' => null,
            ],
            [
                'name' => 'វិជា (Vichea) — Left the shop',
                'email' => 'vichea@gmail.com',
                'role' => Role::Cashier,
                'store_id' => $this->store->id,
                'is_active' => false,
                'permissions' => null,
            ],
        ];

        foreach ($accounts as $account) {
            User::create([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'role' => $account['role'],
                'store_id' => $account['store_id'],
                'is_active' => $account['is_active'] ?? true,
                'permissions' => $account['permissions'],
            ]);
        }

        // Who can appear as a sale's cashier. The departed account is left out
        // on purpose — its history stays, but it rings nothing new up.
        $this->cashiers = User::query()
            ->where('is_active', true)
            ->whereIn('role', [Role::Admin->value, Role::Manager->value, Role::Cashier->value])
            ->get()
            ->all();
    }

    /** One permission's action map. @return array<string, bool> */
    private function actions(bool $all = false, bool $view = false, bool $create = false, bool $update = false, bool $delete = false): array
    {
        return [
            'view' => $all || $view,
            'create' => $all || $create,
            'update' => $all || $update,
            'delete' => $all || $delete,
        ];
    }

    /** @return array<int, Customer> */
    private function seedCustomers(): array
    {
        $people = [
            ['បង ស្រីនាង (Bong Srey Neang)', '012 345 678'],
            ['លោកតា សុផល (Lok Ta Sophal)', '017 222 145'],
            ['មីង ចន្ថូ (Ming Chanthou)', '096 555 010'],
            ['ពូ វណ្ណា (Pu Vanna)', '011 780 344'],
            ['នាង កញ្ញា (Neang Kanha)', '070 419 226'],
            ['បង រិទ្ធី (Bong Rithy)', '015 908 771'],
            ['អុំ សុភ័ក្ត្រ (Om Sopheak)', '092 634 508'],
            ['មីង ស្រីមុំ (Ming Sreymom)', '088 217 940'],
            ['លោក សុវណ្ណ (Lok Sovann)', '010 553 862'],
            ['នាង ដារា (Neang Dara)', '069 330 175'],
            ['បង ចន្ទ្រា (Bong Chandra)', '093 774 021'],
            ['ពូ ខេមរា (Pu Khemara)', '016 208 559'],
            ['មីង សុខា (Ming Sokha)', '077 641 380'],
            ['បង ពិសិដ្ឋ (Bong Piseth)', '098 315 704'],
            ['នាង សុភា (Neang Sopha)', '012 889 236'],
            ['លោក វិចិត្រ (Lok Vichet)', '089 502 917'],
            ['មីង ចន្ទនី (Ming Channy)', '071 436 628'],
            ['បង សំណាង (Bong Samnang)', '017 950 113'],
            ['ពូ ធារ៉ា (Pu Theara)', '095 682 447'],
            ['នាង លីដា (Neang Lyda)', '086 173 590'],
        ];

        return collect($people)
            ->map(fn (array $p) => Customer::create([
                'name' => $p[0],
                'phone' => $p[1],
                'loyalty_points' => random_int(0, 240),
            ]))
            ->all();
    }

    /**
     * Opening stock, as a restock movement rather than a bare number — the
     * inventory screen explains every quantity by its ledger, so a shelf that
     * appeared from nowhere would be the one row it could not account for.
     *
     * @param  array<int, Product>  $products
     */
    private function seedOpeningStock(array $products): void
    {
        $opened = $this->at(self::DAYS, 7, 30);
        $admin = $this->staffBy(Role::Admin);

        foreach ($products as $product) {
            // Packs share the base product's shelf; they have none of their own.
            if ($product->isPack()) {
                continue;
            }

            $qty = random_int(80, 260);

            Stock::create([
                'product_id' => $product->id,
                'store_id' => $this->store->id,
                'qty' => $qty,
                'low_stock_threshold' => random_int(10, 25),
            ]);

            $this->onHand[$product->id] = $qty;

            $this->movement($product, $qty, InventoryLogType::Restock, $admin, $opened, 'Opening stock');
        }
    }

    /**
     * Two months of trading: ordinary sales, a few on credit, the owner's own
     * consumption, and the odd restock when a shelf runs low.
     *
     * @param  array<int, Product>  $products
     * @param  array<int, Customer>  $customers
     */
    private function seedTrading(array $products, array $customers): void
    {
        $sellable = array_values(array_filter($products, fn (Product $p) => $p->is_active));

        for ($daysAgo = self::DAYS; $daysAgo >= 0; $daysAgo--) {
            $date = Carbon::today(config('pos.business_timezone'))->subDays($daysAgo);

            // Weekends are busier, and the shop has been growing — recent days
            // carry more sales than old ones, so the report curves say something.
            $weekend = $date->isWeekend();
            $growth = 1 + (self::DAYS - $daysAgo) / (self::DAYS * 2);
            $count = (int) round(random_int($weekend ? 10 : 5, $weekend ? 16 : 11) * $growth);

            for ($i = 0; $i < $count; $i++) {
                $this->makeOrder($daysAgo, $sellable, $customers);
            }

            // Roughly weekly, top up whatever has fallen below its threshold.
            if ($daysAgo % 7 === 3) {
                $this->restockLowShelves($daysAgo);
            }
        }
    }

    /**
     * One sale, written the way OrderSyncService writes one.
     *
     * @param  array<int, Product>  $sellable
     * @param  array<int, Customer>  $customers
     */
    private function makeOrder(int $daysAgo, array $sellable, array $customers): void
    {
        $at = $this->at($daysAgo, random_int(7, 20), random_int(0, 59));
        $cashier = $this->cashiers[array_rand($this->cashiers)];

        // Most sales are ordinary. About one in eight goes on the book, and
        // the owner takes something for themselves now and then.
        $roll = random_int(1, 100);
        $saleType = match (true) {
            $roll <= 12 => SaleType::Debt,
            $roll <= 17 => SaleType::Myself,
            default => SaleType::Customer,
        };

        $lines = $this->pickLines($sellable);

        if ($lines === []) {
            return; // every candidate shelf was empty
        }

        $subtotal = round(array_sum(array_column($lines, 'subtotal')), 2);

        // A small discount on maybe one sale in ten, and never on the owner's
        // own consumption — there is nobody to discount it for. Riel comes
        // off in note-shaped amounts, not loose change.
        $discount = ($saleType !== SaleType::Myself && random_int(1, 10) === 1)
            ? (float) min(floor($subtotal * 0.1 / 500) * 500, [500, 1000, 2000][random_int(0, 2)])
            : 0.00;

        $total = round($subtotal - $discount, 2);

        [$paid, $change, $payments] = $this->settle($saleType, $total);

        $order = Order::create([
            'client_uuid' => (string) Str::uuid(),
            'order_no' => $this->nextOrderNo($at),
            'store_id' => $this->store->id,
            'register_id' => $this->register->id,
            'cashier_id' => $cashier->id,
            // A debt must name who owes it; an ordinary sale usually does not.
            'customer_id' => match (true) {
                $saleType === SaleType::Debt => $customers[array_rand($customers)]->id,
                $saleType === SaleType::Customer && random_int(1, 4) === 1 => $customers[array_rand($customers)]->id,
                default => null,
            },
            'sale_type' => $saleType,
            'currency' => Currency::current()->code,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'total' => $total,
            'paid_amount' => $paid,
            'change_amount' => $change,
            'status' => OrderStatus::Completed,
            'synced_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        foreach ($lines as $line) {
            $order->items()->create([
                'product_id' => $line['product']->id,
                // Snapshots: the name and price the customer was shown, not
                // whatever the product says today.
                'product_name' => $line['product']->name,
                'unit_price' => $line['unit_price'],
                'qty' => $line['qty'],
                'discount' => 0,
                'subtotal' => $line['subtotal'],
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $this->drawDown($line['product'], $line['qty'], $order, $cashier, $at, $saleType);
        }

        foreach ($payments as [$method, $amount]) {
            $order->payments()->create([
                'method' => $method,
                'amount' => $amount,
                'reference_no' => $method === PaymentMethod::Qr->value ? 'QR'.random_int(100000, 999999) : null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    /**
     * The basket. Packs are picked less often than singles, and a shelf with
     * nothing left on it is skipped rather than sold into the negative.
     *
     * @param  array<int, Product>  $sellable
     * @return array<int, array{product: Product, qty: int, unit_price: float, subtotal: float}>
     */
    private function pickLines(array $sellable): array
    {
        $lines = [];

        for ($i = 0, $n = random_int(1, 4); $i < $n; $i++) {
            $product = $sellable[array_rand($sellable)];

            // Packs are the occasional purchase, not the norm.
            if ($product->isPack() && random_int(1, 4) !== 1) {
                continue;
            }

            $qty = $product->isPack() ? random_int(1, 2) : random_int(1, 4);
            $needed = $product->baseUnits($qty);
            $shelf = $product->isPack() ? $product->parent_product_id : $product->id;

            if (($this->onHand[$shelf] ?? 0) < $needed) {
                continue;
            }

            $unitPrice = (float) $product->sell_price;

            $lines[] = [
                'product' => $product,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => round($unitPrice * $qty, 2),
            ];
        }

        return $lines;
    }

    /**
     * What crossed the counter.
     *
     * A debt is never settled at the till — that is what makes it a debt —
     * though a deposit is common. Change is only ever given against cash, and
     * the owner's own consumption moves no money at all.
     *
     * @return array{0: float, 1: float, 2: array<int, array{0: string, 1: float}>}
     */
    private function settle(SaleType $saleType, float $total): array
    {
        if ($saleType === SaleType::Myself) {
            return [0.00, 0.00, []];
        }

        if ($saleType === SaleType::Debt) {
            // Some tabs are opened with a deposit, most with nothing down.
            // Deposits are handed over in notes: whole 500s, never odd riel.
            $deposit = random_int(1, 3) === 1
                ? (float) (floor($total * random_int(20, 50) / 100 / 500) * 500)
                : 0.00;

            return [
                $deposit,
                0.00,
                $deposit > 0 ? [[PaymentMethod::Cash->value, $deposit]] : [],
            ];
        }

        $method = match (random_int(1, 10)) {
            1, 2 => PaymentMethod::Qr->value,
            3 => PaymentMethod::Card->value,
            default => PaymentMethod::Cash->value,
        };

        if ($method !== PaymentMethod::Cash->value) {
            // Card and QR are always exact — there is no change to give.
            return [$total, 0.00, [[$method, $total]]];
        }

        // Cash is handed over in notes — the till rounds up to the next
        // 500៛ and gives the difference back, which is where change_amount
        // comes from in a shop whose smallest common note is 500.
        $tendered = (float) (ceil($total / 500) * 500);

        return [$tendered, round($tendered - $total, 2), [[$method, $tendered]]];
    }

    /**
     * Take the goods off the shelf and say why.
     *
     * A pack draws units_per_pack off its parent: one case of 24 is 24 cans
     * gone, not one case-shaped thing.
     */
    private function drawDown(Product $product, int $qty, Order $order, User $cashier, Carbon $at, SaleType $saleType): void
    {
        $shelf = $product->isPack() ? $product->parent : $product;
        $units = $product->baseUnits($qty);

        $this->onHand[$shelf->id] = ($this->onHand[$shelf->id] ?? 0) - $units;

        Stock::where('product_id', $shelf->id)
            ->where('store_id', $this->store->id)
            ->update(['qty' => $this->onHand[$shelf->id]]);

        $this->movement(
            $shelf,
            -$units,
            InventoryLogType::Sale,
            $cashier,
            $at,
            $saleType === SaleType::Myself ? 'Taken by the owner' : null,
            $order,
        );
    }

    /** Weekly top-up of anything sitting under its own alert level. */
    private function restockLowShelves(int $daysAgo): void
    {
        $at = $this->at($daysAgo, 8, random_int(0, 45));
        $manager = $this->staffBy(Role::Manager);

        $low = Stock::with('product')
            ->where('store_id', $this->store->id)
            ->whereColumn('qty', '<=', 'low_stock_threshold')
            ->get();

        foreach ($low as $stock) {
            $qty = random_int(60, 180);

            $this->onHand[$stock->product_id] = ($this->onHand[$stock->product_id] ?? 0) + $qty;
            $stock->update(['qty' => $this->onHand[$stock->product_id]]);

            $this->movement($stock->product, $qty, InventoryLogType::Restock, $manager, $at, 'Weekly delivery');
        }
    }

    /** One ledger row. Every quantity change in the shop leaves one. */
    private function movement(
        Product $product,
        int $change,
        InventoryLogType $type,
        User $by,
        Carbon $at,
        ?string $note = null,
        ?Order $order = null,
    ): void {
        InventoryLog::create([
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'type' => $type,
            'qty_change' => $change,
            'reference_type' => $order ? Order::class : null,
            'reference_id' => $order?->id,
            'note' => $note,
            'created_by' => $by->id,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * `S{store}-R{register}-{YYMMDD}-{seq}`, sequenced per store per business
     * day — the same shape OrderSyncService generates, so a demo shop's
     * receipts look like a real one's.
     */
    private function nextOrderNo(Carbon $at): string
    {
        $day = $at->copy()->setTimezone(config('pos.business_timezone'));
        $key = $this->store->id.':'.$day->format('ymd');

        $seq = ($this->orderSeq[$key] ?? 0) + 1;
        $this->orderSeq[$key] = $seq;

        return sprintf(
            'S%d-R%d-%s-%04d',
            $this->store->id,
            $this->register->id,
            $day->format('ymd'),
            $seq,
        );
    }

    /**
     * A moment on a past day, in the shop's own timezone then stored as UTC —
     * the reports group on the business day, so a sale rung up at 8pm in
     * Phnom Penh must not land on the previous date.
     */
    private function at(int $daysAgo, int $hour, int $minute): Carbon
    {
        return Carbon::now(config('pos.business_timezone'))
            ->subDays($daysAgo)
            ->setTime($hour, $minute)
            ->setTimezone('UTC');
    }

    /**
     * Real photos for the products the world has photographed (hotlinked
     * from Wikimedia Commons, verified live), and a brand-coloured label
     * card for the ones it has not — so every shelf face at least matches
     * its name. Swap any of these for a shop's own photo in the edit form.
     *
     * @var array<string, string>
     */
    private const REAL_IMAGES = [
        'ស្រាបៀរ អង្គរ (Angkor) 330ml' => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/8/85/Angkor_beer_bottle.jpg/960px-Angkor_beer_bottle.jpg',
        'ស្រាបៀរ កម្ពុជា (Cambodia) 330ml' => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/c/c2/Cambodia_Lager.jpg/960px-Cambodia_Lager.jpg',
        'ស្រាបៀរ ហ្គាន់ស្បឺក (Ganzberg) 330ml' => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/2/2a/Ganzberg_Brewery_Cambodia.jpg/960px-Ganzberg_Brewery_Cambodia.jpg',
        'ស្រាបៀរ ហនុមាន (Hanuman) 330ml' => 'https://placehold.co/600x600/14324f/f5d67b.png?text=Hanuman%0ABeer',
        'ស្រាបៀរ គ្រុត (Krud) 330ml' => 'https://placehold.co/600x600/1d4ed8/ffffff.png?text=Krud%0ABeer',
        'ស្រាបៀរ ដ្រាហ្គន (Dragon) 330ml' => 'https://placehold.co/600x600/7f1d1d/fbbf24.png?text=Dragon%0ABeer',
    ];

    /** A stable per-product photo link — same SKU, same picture, every seed. */
    private function imageLink(string $sku, string $variant = 'main'): string
    {
        return sprintf('https://picsum.photos/seed/%s-%s/600/600', strtolower($sku), $variant);
    }

    private function staffBy(Role $role): User
    {
        return User::where('role', $role->value)->where('is_active', true)->firstOrFail();
    }
}
