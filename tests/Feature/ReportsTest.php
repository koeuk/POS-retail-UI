<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Services\SalesReporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function sale(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'client_uuid' => (string) Str::uuid(),
            'order_no' => 'NO-'.Str::random(8),
            'store_id' => $this->store->id,
            'register_id' => null,
            'cashier_id' => $this->admin->id,
            'customer_id' => null,
            'subtotal' => '10.00',
            'discount_amount' => '0.00',
            'total' => '11.00',
            'paid_amount' => '11.00',
            'change_amount' => '0.00',
            'status' => OrderStatus::Completed,
            'synced_at' => now(),
            'created_offline_at' => null,
        ], $attributes));
    }

    /* ------------------------------------------------------------------ */
    /* The subtle one */
    /* ------------------------------------------------------------------ */

    /**
     * A sale rung up on Monday but synced on Thursday belongs to Monday.
     * Grouping on created_at would credit the takings to the wrong day and
     * quietly make every daily figure wrong.
     */
    public function test_sales_are_bucketed_by_the_day_they_happened_not_the_day_they_synced(): void
    {
        $soldOn = now()->subDays(3)->startOfDay()->addHours(10);

        // created_at is today (the sync), created_offline_at is three days ago.
        $this->sale(['created_offline_at' => $soldOn, 'total' => '50.00']);

        $reporter = new SalesReporter($this->store->id);

        $onSaleDay = $reporter->summaryFor($soldOn->copy()->startOfDay());
        $onSyncDay = $reporter->summaryFor(SalesReporter::businessNow()->startOfDay());

        $this->assertSame('50.00', $onSaleDay['sales'], 'takings belong to the day of the sale');
        $this->assertSame(1, $onSaleDay['orders']);

        $this->assertSame('0.00', $onSyncDay['sales'], 'the sync day must not be credited');
        $this->assertSame(0, $onSyncDay['orders']);
    }

    public function test_a_sale_with_no_offline_stamp_falls_back_to_created_at(): void
    {
        $this->sale(['total' => '25.00']);

        $summary = (new SalesReporter($this->store->id))->summaryFor(SalesReporter::businessNow()->startOfDay());

        $this->assertSame('25.00', $summary['sales']);
    }

    /* ------------------------------------------------------------------ */
    /* Totals */
    /* ------------------------------------------------------------------ */

    public function test_refunded_and_void_orders_never_count_toward_sales(): void
    {
        $this->sale(['total' => '10.00']);
        $this->sale(['total' => '99.00', 'status' => OrderStatus::Refunded]);
        $this->sale(['total' => '99.00', 'status' => OrderStatus::Void]);

        $summary = (new SalesReporter($this->store->id))->summaryFor(SalesReporter::businessNow()->startOfDay());

        $this->assertSame('10.00', $summary['sales']);
        $this->assertSame(1, $summary['orders']);
    }

    public function test_a_manager_only_sees_their_own_store(): void
    {
        $other = Store::factory()->create();

        $this->sale(['total' => '10.00']);
        $this->sale(['total' => '77.00', 'store_id' => $other->id]);

        $manager = User::factory()->manager($this->store)->create();

        $summary = SalesReporter::for($manager)->summaryFor(SalesReporter::businessNow()->startOfDay());
        $this->assertSame('10.00', $summary['sales']);

        // An admin has no store binding and sees the lot.
        $all = SalesReporter::for($this->admin)->summaryFor(SalesReporter::businessNow()->startOfDay());
        $this->assertSame('87.00', $all['sales']);
    }

    /** A quiet day must read as zero, not vanish and shift the time axis. */
    public function test_days_with_no_sales_still_appear_in_the_series(): void
    {
        $this->sale(['total' => '10.00']);

        $rows = (new SalesReporter($this->store->id))
            ->salesByDay(SalesReporter::businessNow()->subDays(6)->startOfDay(), SalesReporter::businessNow()->startOfDay());

        $this->assertCount(7, $rows);
        $this->assertSame('0.00', $rows->first()['sales']);
        $this->assertSame('10.00', $rows->last()['sales']);
    }

    public function test_the_oversold_list_surfaces_negative_stock(): void
    {
        $product = Product::factory()->create();
        Stock::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'qty' => -4]);

        $oversold = (new SalesReporter($this->store->id))->oversold();

        $this->assertCount(1, $oversold);
        $this->assertSame(-4, $oversold->first()->qty);
    }

    /* ------------------------------------------------------------------ */
    /* Screens */
    /* ------------------------------------------------------------------ */

    public function test_the_dashboard_renders_real_figures(): void
    {
        $this->sale(['total' => '42.00']);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('today.sales', '42.00')
                ->where('today.orders', 1)
                ->has('trend', 7)
                ->has('lowStock')
                ->has('oversold')
                ->has('recentOrders', 1)
            );
    }

    public function test_reports_render_for_a_manager_but_not_a_cashier(): void
    {
        $this->sale(['total' => '15.00']);

        $this->actingAs(User::factory()->manager($this->store)->create())
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Index')
                ->where('totals.sales', '15.00')
                ->has('byDay')
                ->has('byProduct')
                ->has('byPayment')
            );

        $this->actingAs(User::factory()->cashier($this->store)->create())
            ->get(route('reports.index'))
            ->assertForbidden();
    }

    public function test_the_csv_export_streams_the_daily_series(): void
    {
        $this->sale(['total' => '33.00']);

        $response = $this->actingAs($this->admin)
            ->get(route('reports.export', ['from' => SalesReporter::businessNow()->toDateString(), 'to' => SalesReporter::businessNow()->toDateString()]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Date,Orders,Sales', $csv);
        $this->assertStringContainsString('33.00', $csv);
    }

    /** A hand-edited URL must not be able to ask for a decade of daily rows. */
    public function test_an_absurd_date_range_is_clamped(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.index', ['from' => '1990-01-01', 'to' => SalesReporter::businessNow()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('byDay', 367));
    }

    /* ------------------------------------------------------------------ */
    /* The shop's day */
    /* ------------------------------------------------------------------ */

    /**
     * Timestamps are stored in UTC; the shop is not in UTC. A sale rung up at
     * 06:00 in Phnom Penh happened at 23:00 UTC the day before, and grouping it
     * by the UTC date made the morning's takings vanish from today's dashboard.
     */
    public function test_a_sale_is_reported_on_the_shops_day_not_the_servers(): void
    {
        config(['pos.business_timezone' => 'Asia/Phnom_Penh']);

        // 06:00 Monday in Phnom Penh == 23:00 Sunday UTC.
        $utcInstant = Carbon::parse('2026-08-23 23:00:00', 'UTC');

        $order = $this->sale(['total' => '42.00']);
        $order->forceFill(['created_offline_at' => $utcInstant, 'created_at' => $utcInstant])->save();

        $reporter = new SalesReporter($this->store->id);

        $shopMonday = $reporter->summaryFor(Carbon::parse('2026-08-24'));
        $utcSunday = $reporter->summaryFor(Carbon::parse('2026-08-23'));

        $this->assertSame('42.00', number_format((float) $shopMonday['sales'], 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $utcSunday['sales'], 2, '.', ''));
    }

    /** A shop that really does keep UTC is left alone — no conversion at all. */
    public function test_a_utc_shop_skips_the_conversion(): void
    {
        config(['pos.business_timezone' => 'UTC']);

        $this->assertStringNotContainsString('CONVERT_TZ', SalesReporter::businessDay());

        config(['pos.business_timezone' => 'Asia/Phnom_Penh']);

        $this->assertStringContainsString('CONVERT_TZ', SalesReporter::businessDay());
        $this->assertStringContainsString("'+07:00'", SalesReporter::businessDay());
    }

    /** The database goes away for the report's own tables only — everything else keeps working. */
    private function breakTheOrdersTable(): void
    {
        DB::partialMock()
            ->shouldReceive('table')
            ->with('orders')
            ->andThrow(new QueryException('mysql', 'select * from orders', [], new \RuntimeException('Lost connection')));
    }

    public function test_the_report_page_stays_up_with_a_message_when_the_query_fails(): void
    {
        $this->breakTheOrdersTable();

        $this->actingAs($this->admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Index')
                ->where('totals.orders', 0)
                ->where('totals.sales', '0.00')
                ->where('byDay', [])
                ->where('byProduct', [])
                ->where('byPayment', [])
                ->where('flash.error', 'The report could not be loaded. Try again in a moment.')
            );
    }

    public function test_a_failed_export_sends_the_user_back_rather_than_a_broken_file(): void
    {
        $this->breakTheOrdersTable();

        $this->actingAs($this->admin)
            ->from(route('reports.index'))
            ->get(route('reports.export'))
            ->assertRedirect(route('reports.index'))
            ->assertSessionHas('error', 'The report could not be loaded. Try again in a moment.');
    }
}
