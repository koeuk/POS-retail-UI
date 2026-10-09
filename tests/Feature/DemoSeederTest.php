<?php

namespace Tests\Feature;

use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo data has to survive the screens it exists to fill. A seeder that
 * produces figures the reports disagree with is worse than an empty shop.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    public function test_every_screen_opens_on_the_demo_data(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();

        foreach ([
            '/dashboard',
            '/orders',
            '/debts',
            '/consumption',
            '/reports',
            '/products',
            '/categories',
            '/inventory',
            '/customers',
            '/users',
            '/activity',
            '/menu',
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_the_money_adds_up(): void
    {
        $this->assertSame(0, Order::whereRaw('ROUND(total, 2) != ROUND(subtotal - discount_amount, 2)')->count());

        $this->assertSame(0, Order::whereRaw(
            'ROUND(paid_amount, 2) != ROUND((SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payments.order_id = orders.id), 2)'
        )->count());

        $this->assertSame(0, Order::whereRaw(
            'ROUND(subtotal, 2) != ROUND((SELECT COALESCE(SUM(subtotal), 0) FROM order_items WHERE order_items.order_id = orders.id), 2)'
        )->count());
    }

    public function test_debts_behave_like_debts(): void
    {
        $debts = Order::where('sale_type', 'debt');

        $this->assertGreaterThan(0, (clone $debts)->count(), 'the demo shop should have debts to collect');
        $this->assertSame(0, (clone $debts)->whereNull('customer_id')->count());
        $this->assertSame(0, (clone $debts)->whereRaw('paid_amount >= total')->count());
        $this->assertSame(0, (clone $debts)->where('change_amount', '>', 0)->count());
    }

    /** Owner consumption moves stock but must never read as takings. */
    public function test_own_consumption_is_not_revenue(): void
    {
        $mine = Order::where('sale_type', 'myself');

        $this->assertGreaterThan(0, (clone $mine)->count());
        $this->assertSame(0, (clone $mine)->where('paid_amount', '>', 0)->count());
    }

    public function test_no_shelf_was_sold_into_the_negative(): void
    {
        $this->assertSame(0, Stock::where('qty', '<', 0)->count());
    }

    /** Every quantity on the shelf is explained by the movements behind it. */
    public function test_the_ledger_reconciles_with_the_shelf(): void
    {
        foreach (Stock::all() as $stock) {
            $ledger = (int) InventoryLog::where('product_id', $stock->product_id)
                ->where('store_id', $stock->store_id)
                ->sum('qty_change');

            $this->assertSame($ledger, $stock->qty, "shelf and ledger disagree for product {$stock->product_id}");
        }
    }

    /** A pack sells from its parent's shelf and never has one of its own. */
    public function test_packs_share_the_base_product_shelf(): void
    {
        $packs = Product::whereNotNull('parent_product_id');

        $this->assertGreaterThan(0, (clone $packs)->count());
        $this->assertSame(0, Stock::whereIn('product_id', (clone $packs)->select('id'))->count());
    }
}
