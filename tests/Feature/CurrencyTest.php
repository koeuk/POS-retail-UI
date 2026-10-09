<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Register;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderTotals;
use App\Support\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        Setting::put('currency', 'USD');
        Setting::put('riel_per_usd', '4100');
    }

    /* ------------------------------------------------------------------ */
    /* Formatting — no conversion */
    /* ------------------------------------------------------------------ */

    /**
     * A stored amount is already in the shop's currency, so formatting only
     * decides how it looks. Money used to be kept in dollars and multiplied on
     * the way out, which could not express riel: a US cent is 40៛, so a 500៛
     * price became 13 cents and came back as 520៛.
     */
    public function test_formatting_shows_the_stored_amount_untouched(): void
    {
        $this->assertSame('$4.00', Currency::make('USD')->format('4.00'));
        $this->assertSame('$1,234.56', Currency::make('USD')->format(1234.56));

        // 500 stored under a riel shop is 500 riel, not 500 dollars converted.
        $this->assertSame('៛500', Currency::make('KHR')->format('500'));
        $this->assertSame('៛16,400', Currency::make('KHR')->format(16400));
    }

    /** Riel has no fractional unit, and the thousands separator earns its keep. */
    public function test_riel_is_shown_as_whole_riel(): void
    {
        $khr = Currency::make('KHR');

        $this->assertSame('៛1,435', $khr->format('1435.4'));
        $this->assertSame('៛1,353', $khr->format(1352.6));
    }

    /** Arithmetic runs in minor units: cents for dollars, whole riel for riel. */
    public function test_the_minor_unit_follows_the_currency(): void
    {
        $this->assertSame(100, Currency::make('USD')->minorFactor());
        $this->assertSame(1, Currency::make('KHR')->minorFactor());
    }

    /**
     * Conversion survives for one purpose only: the migration that moved the
     * stored base from dollars to the shop's own money.
     */
    public function test_conversion_is_still_available_for_the_migration(): void
    {
        Setting::put('riel_per_usd', '4000');

        $this->assertSame(4000.0, Currency::make('KHR')->fromUsd('1.00'));
        $this->assertSame(4.0, Currency::make('USD')->fromUsd('4.00'));
    }

    /** A stale or hand-edited setting must not crash every page that formats a price. */
    public function test_an_unknown_currency_code_falls_back_to_usd(): void
    {
        Setting::put('currency', 'XYZ');

        $this->assertSame('USD', Currency::current()->code);
    }

    /* ------------------------------------------------------------------ */
    /* The setting drives the whole app */
    /* ------------------------------------------------------------------ */

    public function test_switching_the_setting_flips_what_every_page_receives(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currency.code', 'USD')
                ->where('currency.symbol', '$')
                ->where('currency.decimals', 2)
            );

        Setting::put('currency', 'KHR');

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currency.code', 'KHR')
                ->where('currency.symbol', '៛')
                ->where('currency.decimals', 0)
                ->where('currency.riel_per_usd', 4100)
            );
    }

    public function test_the_pos_feed_and_public_menu_carry_the_currency(): void
    {
        Setting::put('currency', 'KHR');

        $this->actingAs($this->admin)
            ->getJson(route('pos.data.products'))
            ->assertOk()
            ->assertJsonPath('settings.currency.code', 'KHR');

        $this->get(route('menu'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shop.currency.code', 'KHR'));
    }

    /* ------------------------------------------------------------------ */
    /* The settings screen */
    /* ------------------------------------------------------------------ */

    public function test_an_admin_can_change_the_currency_and_rate(): void
    {
        $this->actingAs($this->admin)
            ->put(route('shop.update'), [
                'receipt_header' => 'My Shop',
                'receipt_footer' => 'Come again',
                'currency' => 'KHR',
                'riel_per_usd' => 4050,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('KHR', Setting::get('currency'));
        $this->assertSame('4050', Setting::get('riel_per_usd'));
        $this->assertSame('My Shop', Setting::get('receipt_header'));
    }

    /** A rate of 0 would make every riel price ៛0; a rate of a million is a typo. */
    public function test_an_absurd_rate_is_rejected(): void
    {
        foreach ([0, -1, 1000000, 'abc'] as $bad) {
            $this->actingAs($this->admin)
                ->put(route('shop.update'), [
                    'receipt_header' => 'Shop',
                    'currency' => 'USD',
                    'riel_per_usd' => $bad,
                ])
                ->assertSessionHasErrors('riel_per_usd');
        }

        $this->assertSame('4100', Setting::get('riel_per_usd'), 'the stored rate must be untouched');
    }

    public function test_an_unsupported_currency_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put(route('shop.update'), ['receipt_header' => 'Shop', 'currency' => 'EUR', 'riel_per_usd' => 4100])
            ->assertSessionHasErrors('currency');
    }

    /** Shop settings change what every cashier sees — a manager must not reach them. */
    public function test_only_an_admin_can_reach_shop_settings(): void
    {
        $store = Store::factory()->create();
        $manager = User::factory()->manager($store)->create();

        $this->actingAs($manager)->get(route('shop.edit'))->assertForbidden();
        $this->actingAs($manager)
            ->put(route('shop.update'), ['receipt_header' => 'x', 'currency' => 'KHR', 'riel_per_usd' => 4100])
            ->assertForbidden();

        $this->assertSame('USD', Setting::get('currency'), 'nothing changed');
        $this->actingAs($this->admin)->get(route('shop.edit'))->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* Riel prices survive a sale exactly */
    /* ------------------------------------------------------------------ */

    /**
     * The reason the stored base moved. With money kept in USD cents a riel
     * price could only land on a multiple of 40៛ — 500៛ became 13 cents and
     * came back as 520៛, on the shelf label, the receipt and the report alike.
     */
    public function test_a_riel_price_is_not_quantised_by_cents(): void
    {
        Setting::put('currency', 'KHR');

        $totals = new OrderTotals([
            ['qty' => 1, 'unit_price' => '500', 'discount' => 0],
            ['qty' => 3, 'unit_price' => '1500', 'discount' => 0],
        ]);

        // 500 + 4,500. Not 520 + 4,560.
        //
        // No decimal places: riel has no fractional unit, so the string this
        // hands to the money column carries none either.
        $this->assertSame('5000', $totals->subtotal());
        $this->assertSame('5000', $totals->total());
    }

    public function test_dollars_still_work_to_the_cent(): void
    {
        Setting::put('currency', 'USD');

        $totals = new OrderTotals([
            ['qty' => 3, 'unit_price' => '0.75', 'discount' => 0],
            ['qty' => 1, 'unit_price' => '1.05', 'discount' => '0.05'],
        ]);

        $this->assertSame('3.25', $totals->subtotal());
    }

    /** An order records the currency it was rung up in, so history keeps its meaning. */
    public function test_an_order_remembers_which_currency_it_was_rung_up_in(): void
    {
        Setting::put('currency', 'KHR');

        $store = Store::factory()->create();
        $register = Register::factory()->create(['store_id' => $store->id]);
        $cashier = User::factory()->cashier($store)->create();
        $product = Product::factory()->create(['sell_price' => '2000']);
        Stock::create(['product_id' => $product->id, 'store_id' => $store->id, 'qty' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.data.orders.sync'), ['orders' => [[
            'client_uuid' => (string) Str::uuid(),
            'register_id' => $register->id,
            'customer_id' => null,
            'created_offline_at' => now()->toIso8601String(),
            'discount_amount' => '0',
            'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'qty' => 2,
                'unit_price' => '2000', 'discount' => '0']],
            'payments' => [['method' => 'cash', 'amount' => '5000', 'reference_no' => null]],
        ]]])->assertOk();

        $order = Order::firstOrFail();

        $this->assertSame('KHR', $order->currency);
        $this->assertSame('4000.00', $order->total);
        $this->assertSame('1000.00', $order->change_amount);
    }
}
