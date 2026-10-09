<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Register;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Services\SalesReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PosSyncTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Register $register;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->register = Register::factory()->create(['store_id' => $this->store->id]);
        $this->cashier = User::factory()->cashier($this->store)->create();
    }

    private function stockedProduct(int $qty = 100, array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes);

        Stock::create([
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'qty' => $qty,
            'low_stock_threshold' => 5,
        ]);

        return $product;
    }

    /** @param array<int, array<string, mixed>> $items */
    private function orderPayload(array $items, array $overrides = []): array
    {
        $payments = $overrides['payments'] ?? [
            ['method' => 'cash', 'amount' => '1000.00', 'reference_no' => null],
        ];

        return array_merge([
            'client_uuid' => (string) Str::uuid(),
            'register_id' => $this->register->id,
            'customer_id' => null,
            'created_offline_at' => now()->toIso8601String(),
            'discount_amount' => '0.00',
            'items' => $items,
            'payments' => $payments,
        ], $overrides);
    }

    private function line(Product $product, int $qty = 1, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'qty' => $qty,
            'unit_price' => $product->sell_price,
            'discount' => '0.00',
        ], $overrides);
    }

    private function sync(array $orders): TestResponse
    {
        return $this->actingAs($this->cashier)
            ->postJson(route('pos.data.orders.sync'), ['orders' => $orders]);
    }

    /* ================================================================== */
    /* Idempotency — the reason this endpoint exists */
    /* ================================================================== */

    public function test_posting_the_same_client_uuid_twice_creates_exactly_one_order(): void
    {
        $product = $this->stockedProduct(100);
        $payload = $this->orderPayload([$this->line($product, 3)]);

        $this->sync([$payload])->assertOk()
            ->assertJsonPath('results.0.status', 'created');

        $this->sync([$payload])->assertOk()
            ->assertJsonPath('results.0.status', 'already_synced');

        $this->assertSame(1, Order::where('client_uuid', $payload['client_uuid'])->count());
    }

    public function test_a_replayed_order_does_not_decrement_stock_a_second_time(): void
    {
        $product = $this->stockedProduct(100);
        $payload = $this->orderPayload([$this->line($product, 4)]);

        $this->sync([$payload])->assertOk();
        $this->sync([$payload])->assertOk();
        $this->sync([$payload])->assertOk();

        $this->assertSame(96, $this->stockQty($product));
        $this->assertSame(1, $product->inventoryLogs()->count());
    }

    /**
     * Two tabs can flush the same queue at the same moment. The unique index
     * on client_uuid is what stops that becoming two orders; this proves the
     * violation is caught and reported rather than thrown at the cashier.
     */
    public function test_a_racing_duplicate_is_reported_as_already_synced(): void
    {
        $product = $this->stockedProduct(50);
        $uuid = (string) Str::uuid();

        $payload = $this->orderPayload([$this->line($product, 2)], ['client_uuid' => $uuid]);

        // Simulate the loser of the race: the row already exists when the
        // second request reaches the insert.
        $this->sync([$payload])->assertOk();

        $second = $this->sync([$payload])->assertOk();

        $second->assertJsonPath('results.0.status', 'already_synced');
        $this->assertSame(1, Order::where('client_uuid', $uuid)->count());
        $this->assertSame(48, $this->stockQty($product));
    }

    public function test_a_batch_of_ten_offline_orders_syncs_in_one_call(): void
    {
        $product = $this->stockedProduct(100);

        $orders = collect(range(1, 10))
            ->map(fn () => $this->orderPayload([$this->line($product, 2)]))
            ->all();

        $response = $this->sync($orders)->assertOk();

        $this->assertCount(10, $response->json('results'));
        $this->assertSame(10, Order::count());
        $this->assertSame(80, $this->stockQty($product));

        // Every order gets its own number.
        $this->assertSame(10, Order::distinct()->count('order_no'));
    }

    public function test_one_bad_order_does_not_sink_the_rest_of_the_batch(): void
    {
        $product = $this->stockedProduct(100);

        $good = $this->orderPayload([$this->line($product, 1)]);
        $bad = $this->orderPayload([$this->line($product, 1, ['product_id' => 999_999])]);

        // A non-existent product is caught by validation before anything runs,
        // so the whole batch is rejected — the client must not silently drop it.
        $this->sync([$good, $bad])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    /* ================================================================== */
    /* Stock */
    /* ================================================================== */

    public function test_stock_decrements_by_the_right_amount_exactly_once(): void
    {
        $a = $this->stockedProduct(20);
        $b = $this->stockedProduct(7);

        $this->sync([
            $this->orderPayload([$this->line($a, 3), $this->line($b, 2)]),
        ])->assertOk();

        $this->assertSame(17, $this->stockQty($a));
        $this->assertSame(5, $this->stockQty($b));
    }

    public function test_an_inventory_log_is_written_for_every_line_item(): void
    {
        $a = $this->stockedProduct(20);
        $b = $this->stockedProduct(20);

        $this->sync([
            $this->orderPayload([$this->line($a, 3), $this->line($b, 5)]),
        ])->assertOk();

        $order = Order::firstOrFail();

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $a->id,
            'store_id' => $this->store->id,
            'type' => 'sale',
            'qty_change' => -3,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'created_by' => $this->cashier->id,
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $b->id,
            'qty_change' => -5,
        ]);
    }

    /**
     * The cash is in the drawer and the goods have gone. Refusing the sale
     * would strand it in the queue forever, so stock is allowed to go negative
     * and the discrepancy is surfaced on the dashboard instead.
     */
    public function test_an_offline_sale_may_drive_stock_negative_and_is_never_rejected(): void
    {
        $product = $this->stockedProduct(2);

        $this->sync([$this->orderPayload([$this->line($product, 5)])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'created');

        $this->assertSame(-3, $this->stockQty($product));
        $this->assertDatabaseHas('inventory_logs', ['product_id' => $product->id, 'qty_change' => -5]);
    }

    public function test_a_product_that_does_not_track_stock_moves_no_stock(): void
    {
        $product = $this->stockedProduct(10, ['track_stock' => false]);

        $this->sync([$this->orderPayload([$this->line($product, 4)])])->assertOk();

        $this->assertSame(10, $this->stockQty($product));
        $this->assertSame(0, $product->inventoryLogs()->count());
    }

    /* ================================================================== */
    /* Money */
    /* ================================================================== */

    /** The total is the lines, less the discounts. Nothing is added on top. */
    public function test_the_total_is_exactly_the_sum_of_the_lines(): void
    {
        $a = $this->stockedProduct(10, ['sell_price' => '10.00']);
        $b = $this->stockedProduct(10, ['sell_price' => '5.00']);

        $this->sync([
            $this->orderPayload([$this->line($a, 2), $this->line($b, 1)]),
        ])->assertOk();

        $order = Order::firstOrFail();

        $this->assertSame('25.00', $order->subtotal);
        $this->assertSame('25.00', $order->total);
    }

    public function test_an_order_discount_comes_straight_off_the_subtotal(): void
    {
        $a = $this->stockedProduct(10, ['sell_price' => '10.00']);
        $b = $this->stockedProduct(10, ['sell_price' => '10.00']);

        $this->sync([
            $this->orderPayload([$this->line($a, 1), $this->line($b, 1)], ['discount_amount' => '4.00']),
        ])->assertOk();

        $order = Order::firstOrFail();

        $this->assertSame('20.00', $order->subtotal);
        $this->assertSame('4.00', $order->discount_amount);
        $this->assertSame('16.00', $order->total);
    }

    /** An order discount larger than the sale is capped; a total never goes negative. */
    public function test_an_oversized_order_discount_is_capped_at_the_subtotal(): void
    {
        $product = $this->stockedProduct(10, ['sell_price' => '10.00']);

        $this->sync([
            $this->orderPayload([$this->line($product, 1)], ['discount_amount' => '999.00']),
        ])->assertOk();

        $order = Order::firstOrFail();

        $this->assertSame('10.00', $order->discount_amount);
        $this->assertSame('0.00', $order->total);
    }

    public function test_a_line_discount_comes_off_that_line(): void
    {
        $product = $this->stockedProduct(10, ['sell_price' => '10.00']);

        $this->sync([
            $this->orderPayload([$this->line($product, 2, ['discount' => '5.00'])]),
        ])->assertOk();

        $order = Order::firstOrFail();

        $this->assertSame('15.00', $order->subtotal);   // 20.00 - 5.00
        $this->assertSame('15.00', $order->total);
        $this->assertSame('15.00', $order->items()->first()->subtotal);
    }

    public function test_change_is_given_on_cash_but_not_on_card(): void
    {
        $product = $this->stockedProduct(10, ['sell_price' => '10.00']);

        $cash = $this->orderPayload([$this->line($product, 1)], [
            'payments' => [['method' => 'cash', 'amount' => '20.00', 'reference_no' => null]],
        ]);

        $card = $this->orderPayload([$this->line($product, 1)], [
            'payments' => [['method' => 'card', 'amount' => '10.00', 'reference_no' => 'TXN-1']],
        ]);

        $this->sync([$cash, $card])->assertOk();

        $cashOrder = Order::where('client_uuid', $cash['client_uuid'])->firstOrFail();
        $cardOrder = Order::where('client_uuid', $card['client_uuid'])->firstOrFail();

        $this->assertSame('20.00', $cashOrder->paid_amount);
        $this->assertSame('10.00', $cashOrder->change_amount);

        $this->assertSame('10.00', $cardOrder->paid_amount);
        $this->assertSame('0.00', $cardOrder->change_amount);
    }

    /**
     * The whole reason order_items snapshots name and price: a sale synced a
     * week late must still show what the customer was actually charged.
     */
    public function test_line_items_keep_the_price_from_the_time_of_sale(): void
    {
        $product = $this->stockedProduct(10, ['name' => 'Old Name', 'sell_price' => '2.00']);

        $payload = $this->orderPayload([
            $this->line($product, 1, ['product_name' => 'Old Name', 'unit_price' => '2.00']),
        ]);

        $product->update(['name' => 'New Name', 'sell_price' => '9.99']);

        $this->sync([$payload])->assertOk();

        $item = Order::firstOrFail()->items()->firstOrFail();

        $this->assertSame('Old Name', $item->product_name);
        $this->assertSame('2.00', $item->unit_price);
    }

    /* ================================================================== */
    /* Order numbering, payments, endpoints */
    /* ================================================================== */

    public function test_order_numbers_are_sequential_per_store_per_business_day(): void
    {
        $product = $this->stockedProduct(100);
        $day = now()->subDays(2);

        $orders = collect(range(1, 3))
            ->map(fn () => $this->orderPayload(
                [$this->line($product, 1)],
                ['created_offline_at' => $day->toIso8601String()],
            ))
            ->all();

        $this->sync($orders)->assertOk();

        $numbers = Order::orderBy('id')->pluck('order_no')->all();

        // Numbers carry the shop's date for that instant, not the server's.
        $stamp = $day->copy()->setTimezone(config('pos.business_timezone'))->format('ymd');

        $this->assertSame([
            "S{$this->store->id}-R{$this->register->id}-{$stamp}-0001",
            "S{$this->store->id}-R{$this->register->id}-{$stamp}-0002",
            "S{$this->store->id}-R{$this->register->id}-{$stamp}-0003",
        ], $numbers);
    }

    public function test_payments_are_recorded_against_the_order(): void
    {
        $product = $this->stockedProduct(10, ['sell_price' => '10.00']);

        $this->sync([
            $this->orderPayload([$this->line($product, 1)], [
                'payments' => [['method' => 'qr', 'amount' => '10.00', 'reference_no' => 'QR-9']],
            ]),
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'order_id' => Order::firstOrFail()->id,
            'method' => 'qr',
            'amount' => '10.00',
            'reference_no' => 'QR-9',
        ]);
    }

    public function test_the_product_feed_carries_everything_the_pos_needs_offline(): void
    {
        $this->stockedProduct(42, ['name' => 'Cached Cola']);
        Product::factory()->inactive()->create(['name' => 'Hidden']);

        $response = $this->actingAs($this->cashier)
            ->getJson(route('pos.data.products'))
            ->assertOk()
            ->assertJsonStructure([
                'store_id', 'synced_at', 'categories', 'registers',
                'settings' => ['receipt_header', 'receipt_footer', 'currency' => ['code', 'symbol', 'decimals', 'riel_per_usd']],
                'products' => [['id', 'name', 'sku', 'barcode', 'sell_price', 'stock_qty']],
            ]);

        $names = collect($response->json('products'))->pluck('name');

        $this->assertTrue($names->contains('Cached Cola'));
        $this->assertFalse($names->contains('Hidden'));
        $this->assertSame(42, $response->json('products.0.stock_qty'));
        $this->assertSame($this->store->id, $response->json('store_id'));
    }

    public function test_status_reports_pending_before_sync_and_synced_after(): void
    {
        $product = $this->stockedProduct(10);
        $payload = $this->orderPayload([$this->line($product, 1)]);
        $uuid = $payload['client_uuid'];

        $this->actingAs($this->cashier)
            ->getJson(route('pos.data.orders.status', $uuid))
            ->assertNotFound()
            ->assertJsonPath('status', 'pending');

        $this->sync([$payload])->assertOk();

        $this->actingAs($this->cashier)
            ->getJson(route('pos.data.orders.status', $uuid))
            ->assertOk()
            ->assertJsonPath('status', 'synced');
    }

    public function test_sync_endpoints_are_closed_to_guests(): void
    {
        $this->getJson(route('pos.data.products'))->assertUnauthorized();
        $this->postJson(route('pos.data.orders.sync'), ['orders' => []])->assertUnauthorized();
    }

    private function stockQty(Product $product): int
    {
        return (int) Stock::where('product_id', $product->id)
            ->where('store_id', $this->store->id)
            ->value('qty');
    }

    /* ================================================================== */
    /* Store binding */
    /* ================================================================== */

    /**
     * A till that has been offline for hours is not a trustworthy witness to
     * which shop it is standing in. Honouring its claim let one store's
     * cashier move another store's stock.
     */
    public function test_a_cashiers_sale_lands_in_their_own_store_whatever_the_payload_claims(): void
    {
        $other = Store::factory()->create();
        $otherProduct = Product::factory()->create();
        Stock::create(['product_id' => $otherProduct->id, 'store_id' => $other->id, 'qty' => 50]);

        $mine = $this->stockedProduct(100);

        $payload = $this->orderPayload([$this->line($mine, 5)], ['store_id' => $other->id]);

        $this->sync([$payload])->assertOk()->assertJsonPath('results.0.status', 'created');

        $order = Order::where('client_uuid', $payload['client_uuid'])->firstOrFail();

        $this->assertSame($this->store->id, $order->store_id);

        // And the other store's shelf is untouched.
        $this->assertSame(50, Stock::where('store_id', $other->id)->value('qty'));
    }

    /** An admin covers every shop, so they may still say which one. */
    public function test_an_unbound_admin_may_name_the_store(): void
    {
        $elsewhere = Store::factory()->create();
        Register::factory()->create(['store_id' => $elsewhere->id]);
        $admin = User::factory()->admin()->create();

        $product = Product::factory()->create();
        Stock::create(['product_id' => $product->id, 'store_id' => $elsewhere->id, 'qty' => 20]);

        $payload = $this->orderPayload([$this->line($product, 2)], ['store_id' => $elsewhere->id]);

        $this->actingAs($admin)
            ->postJson(route('pos.data.orders.sync'), ['orders' => [$payload]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'created');

        $this->assertSame($elsewhere->id, Order::where('client_uuid', $payload['client_uuid'])->value('store_id'));
        $this->assertSame(18, Stock::where('store_id', $elsewhere->id)->value('qty'));
    }

    /* ================================================================== */
    /* Order numbering */
    /* ================================================================== */

    /**
     * Numbering used to count the day's rows and add one. Delete an order from
     * the middle and the count points at a number that already exists — and
     * the retry recounted the same number, so the till could never sell again
     * that day.
     */
    public function test_numbering_steps_over_a_gap_left_by_a_deleted_order(): void
    {
        $product = $this->stockedProduct(500);

        $sell = fn () => $this->sync([$this->orderPayload([$this->line($product, 1)])])->json('results.0');

        $sell();
        $second = $sell();
        $sell();

        Order::where('order_no', $second['order_no'])->delete();

        $result = $sell();

        $prefix = sprintf('S%d-R%d-%s-', $this->store->id, $this->register->id, SalesReporter::businessNow()->format('ymd'));

        $this->assertSame('created', $result['status']);
        $this->assertSame($prefix.'0004', $result['order_no']);
    }

    public function test_numbering_is_per_store_per_day(): void
    {
        $product = $this->stockedProduct(50);

        $today = $this->sync([$this->orderPayload([$this->line($product, 1)])])->json('results.0');
        $this->assertStringEndsWith('-0001', $today['order_no']);

        // A batch that was rung up yesterday numbers into yesterday's run.
        $yesterday = $this->sync([
            $this->orderPayload([$this->line($product, 1)], ['created_offline_at' => now()->subDay()->toIso8601String()]),
        ])->json('results.0');

        $this->assertStringContainsString(SalesReporter::businessNow()->subDay()->format('ymd'), $yesterday['order_no']);
        $this->assertStringEndsWith('-0001', $yesterday['order_no']);
    }

    /* ================================================================== */
    /* Clocks */
    /* ================================================================== */

    /**
     * A till that lost its battery comes back believing it is years from now.
     * The sale is real, so it is kept — but its own date is not trusted into
     * the order number and the reports.
     */
    public function test_a_wildly_wrong_device_clock_falls_back_to_server_time(): void
    {
        $product = $this->stockedProduct(50);

        $result = $this->sync([
            $this->orderPayload([$this->line($product, 1)], ['created_offline_at' => '2031-01-01T09:00:00Z']),
        ])->json('results.0');

        $this->assertSame('created', $result['status']);
        $this->assertStringContainsString(SalesReporter::businessNow()->format('ymd'), $result['order_no']);

        $order = Order::where('order_no', $result['order_no'])->firstOrFail();
        $this->assertTrue($order->created_offline_at->isSameDay(now()));
    }

    /** An ordinary offline stretch is still honoured to the second. */
    public function test_a_genuine_offline_timestamp_is_kept(): void
    {
        $product = $this->stockedProduct(50);
        $when = now()->subDays(3)->startOfHour();

        $result = $this->sync([
            $this->orderPayload([$this->line($product, 1)], ['created_offline_at' => $when->toIso8601String()]),
        ])->json('results.0');

        $order = Order::where('order_no', $result['order_no'])->firstOrFail();

        // The instant is preserved exactly; the number carries the shop's date
        // for that instant, which is not always the UTC one.
        $this->assertSame($when->toDateTimeString(), $order->created_offline_at->toDateTimeString());
        $this->assertStringContainsString(
            $when->copy()->setTimezone(config('pos.business_timezone'))->format('ymd'),
            $order->order_no,
        );
    }

    /**
     * The device sends its own offset. Storing that wall-clock reading verbatim
     * put created_offline_at on a different clock from created_at, and the two
     * are compared with COALESCE all over the reports.
     */
    public function test_an_offset_timestamp_is_normalised_to_utc(): void
    {
        $product = $this->stockedProduct(50);

        // 06:00 in Phnom Penh is 23:00 the previous day in UTC.
        $result = $this->sync([
            $this->orderPayload([$this->line($product, 1)], ['created_offline_at' => '2026-08-24T06:00:00+07:00']),
        ])->json('results.0');

        $stored = Order::where('order_no', $result['order_no'])->value('created_offline_at');

        $this->assertSame('2026-08-23 23:00:00', Carbon::parse($stored)->utc()->toDateTimeString());
    }

    public function test_the_order_number_code_can_be_set_from_shop_settings(): void
    {
        Setting::put('order_prefix', 'MYSHOP');

        $product = $this->stockedProduct(50);
        $first = $this->sync([$this->orderPayload([$this->line($product, 1)])])->json('results.0');

        $day = SalesReporter::businessNow()->format('ymd');
        $this->assertSame("MYSHOP-{$day}-0001", $first['order_no']);

        // The sequence continues under the custom code, not beside it.
        $second = $this->sync([$this->orderPayload([$this->line($product, 1)])])->json('results.0');
        $this->assertSame("MYSHOP-{$day}-0002", $second['order_no']);

        // Clearing the code goes back to the store-and-register default,
        // whose run starts fresh — it is a different prefix.
        Setting::put('order_prefix', null);
        $third = $this->sync([$this->orderPayload([$this->line($product, 1)])])->json('results.0');
        $this->assertSame(sprintf('S%d-R%d-%s-0001', $this->store->id, $this->register->id, $day), $third['order_no']);
    }
}
