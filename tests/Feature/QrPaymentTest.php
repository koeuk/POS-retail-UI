<?php

namespace Tests\Feature;

use App\Enums\QrChargeStatus;
use App\Models\Activity;
use App\Models\Order;
use App\Models\Product;
use App\Models\QrCharge;
use App\Models\Register;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Payments\PaymentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class QrPaymentTest extends TestCase
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

        Setting::put('currency', 'KHR');
        Http::preventStrayRequests();
    }

    private function useBakong(): void
    {
        Setting::put(PaymentSettings::PROVIDER, 'bakong');
        Setting::put(PaymentSettings::ACCOUNT_ID, 'cornermart@aclb');
        Setting::put(PaymentSettings::MERCHANT_NAME, 'Corner Mart');
        PaymentSettings::putSecret(PaymentSettings::BAKONG_TOKEN, 'secret-token');
    }

    private function bakongSays(array $body, int $status = 200): void
    {
        Http::fake(['*/v1/check_transaction_by_md5' => Http::response($body, $status)]);
    }

    /** Successive answers, one per call — a second Http::fake() would not replace the first. */
    private function bakongAnswers(array ...$answers): void
    {
        $sequence = Http::sequence();
        foreach ($answers as [$body, $status]) {
            $sequence->push($body, $status);
        }
        Http::fake(['*/v1/check_transaction_by_md5' => $sequence]);
    }

    private function paidBody(string $amount = '16400', string $to = 'cornermart@aclb'): array
    {
        return [
            'responseCode' => 0,
            'responseMessage' => 'Getting transaction successfully.',
            'errorCode' => null,
            'data' => [
                'hash' => 'abc123hash',
                'fromAccountId' => 'customer@wing',
                'toAccountId' => $to,
                'currency' => 'KHR',
                'amount' => $amount,
            ],
        ];
    }

    private const NOT_FOUND = ['responseCode' => 1, 'responseMessage' => 'Transaction could not be found.', 'errorCode' => 1, 'data' => null];

    private function openCharge(string $amount = '16400'): array
    {
        return $this->actingAs($this->cashier)
            ->postJson('/pos/data/qr/charges', ['amount' => $amount, 'register_id' => $this->register->id])
            ->assertCreated()
            ->json();
    }

    public function test_feed_carries_the_static_qr_and_provider(): void
    {
        $this->useBakong();

        $qr = $this->actingAs($this->cashier)->getJson('/pos/data/products')->assertOk()->json('settings.qr');

        $this->assertSame('bakong', $qr['provider']);
        $this->assertTrue($qr['dynamic']);
        $this->assertStringStartsWith('<svg', $qr['static_svg']);
        // The token is a server-side secret and must never reach a till.
        $this->assertStringNotContainsString('secret-token', json_encode($qr));
    }

    public function test_manual_provider_is_not_dynamic_and_refuses_per_sale_charges(): void
    {
        Setting::put(PaymentSettings::ACCOUNT_ID, 'cornermart@aclb');

        $this->actingAs($this->cashier)->getJson('/pos/data/products')->assertJsonPath('settings.qr.dynamic', false);
        $this->actingAs($this->cashier)->postJson('/pos/data/qr/charges', ['amount' => '1000'])->assertStatus(503);
    }

    public function test_opening_a_charge_mints_a_khqr_and_records_it(): void
    {
        $this->useBakong();

        $charge = $this->openCharge();

        $row = QrCharge::where('uuid', $charge['id'])->firstOrFail();
        $this->assertSame('bakong', $row->provider);
        $this->assertSame(md5($row->qr), $row->reference);
        $this->assertSame($this->store->id, $row->store_id);
        $this->assertStringContainsString('540516400', $row->qr);
        $this->assertStringStartsWith('<svg', $charge['svg']);
        Http::assertNothingSent(); // Bakong QRs are minted locally.
    }

    public function test_riel_charges_reject_fractions(): void
    {
        $this->useBakong();

        $this->actingAs($this->cashier)
            ->postJson('/pos/data/qr/charges', ['amount' => '1000.50'])
            ->assertJsonValidationErrors('amount');
    }

    public function test_poll_stays_pending_until_bakong_finds_the_payment(): void
    {
        $this->useBakong();
        $charge = $this->openCharge();

        $this->bakongAnswers([self::NOT_FOUND, 200], [$this->paidBody(), 200]);
        $this->getJson("/pos/data/qr/charges/{$charge['id']}")->assertJsonPath('status', 'pending');

        $this->travel(9)->seconds();
        $this->getJson("/pos/data/qr/charges/{$charge['id']}")
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('settled', true);

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer secret-token')
            && $r->hasHeader('X-API-Key', 'secret-token')
            && $r['md5'] === $charge['reference']);

        $this->assertTrue(Activity::where('event', 'qr_paid')->exists());
    }

    public function test_a_payment_that_does_not_match_is_flagged_not_accepted(): void
    {
        $this->useBakong();
        $charge = $this->openCharge();

        $this->bakongSays($this->paidBody(amount: '100'));
        $this->getJson("/pos/data/qr/charges/{$charge['id']}")
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('settled', false);
    }

    public function test_a_refused_token_is_reported_but_keeps_the_charge_pending(): void
    {
        $this->useBakong();
        $charge = $this->openCharge();

        $this->bakongSays([], 401);
        $this->getJson("/pos/data/qr/charges/{$charge['id']}")
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('notice', fn ($n) => str_contains($n, 'token'));
    }

    public function test_cashier_can_confirm_by_hand_and_it_is_audited(): void
    {
        $this->useBakong();
        $charge = $this->openCharge();

        $this->postJson("/pos/data/qr/charges/{$charge['id']}/confirm")->assertJsonPath('status', 'manual');

        $this->assertSame($this->cashier->id, QrCharge::first()->confirmed_by);
        $this->assertTrue(Activity::where('event', 'qr_manual')->exists());
    }

    public function test_hand_confirmation_can_be_switched_off(): void
    {
        $this->useBakong();
        Setting::put(PaymentSettings::MANUAL_CONFIRM, '0');
        $charge = $this->openCharge();

        $this->postJson("/pos/data/qr/charges/{$charge['id']}/confirm")->assertForbidden();
    }

    public function test_a_cashier_cannot_see_another_stores_charge(): void
    {
        $this->useBakong();
        $charge = $this->openCharge();

        $other = User::factory()->cashier(Store::factory()->create())->create();

        $this->actingAs($other)->getJson("/pos/data/qr/charges/{$charge['id']}")->assertNotFound();
    }

    public function test_synced_sale_is_linked_to_its_charge(): void
    {
        $this->useBakong();
        $charge = $this->openCharge();

        $product = Product::factory()->create(['sell_price' => 16400]);
        Stock::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'qty' => 10, 'low_stock_threshold' => 1]);

        $this->postJson('/pos/data/orders/sync', ['orders' => [[
            'client_uuid' => (string) Str::uuid(),
            'register_id' => $this->register->id,
            'created_offline_at' => now()->toIso8601String(),
            'discount_amount' => '0',
            'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'qty' => 1, 'unit_price' => '16400', 'discount' => '0']],
            'payments' => [['method' => 'qr', 'amount' => '16400', 'reference_no' => $charge['reference']]],
        ]]])->assertJsonPath('results.0.status', 'created');

        $order = Order::latest('id')->first();
        $this->assertSame($order->id, QrCharge::first()->order_id);
        $this->assertSame($charge['reference'], $order->payments()->first()->reference_no);
    }

    public function test_reconcile_catches_a_payment_made_after_the_till_gave_up(): void
    {
        $this->useBakong();
        $charge = $this->openCharge();
        $this->postJson("/pos/data/qr/charges/{$charge['id']}/cancel")->assertJsonPath('status', 'cancelled');
        $this->travel(5)->minutes();

        $this->bakongSays($this->paidBody());
        $this->artisan('payments:reconcile-qr')->expectsOutputToContain('1 late QR payment')->assertSuccessful();

        $this->assertSame(QrChargeStatus::Paid, QrCharge::first()->status);
    }

    public function test_expired_unpaid_charges_are_marked_expired(): void
    {
        $this->useBakong();
        $this->openCharge();

        $this->travel(10)->minutes();
        $this->bakongSays(self::NOT_FOUND);
        $this->artisan('payments:reconcile-qr')->assertSuccessful();

        $this->assertSame(QrChargeStatus::Expired, QrCharge::first()->status);
    }

    public function test_payment_settings_are_admin_only(): void
    {
        $this->actingAs(User::factory()->manager()->create())->get('/settings/payments')->assertForbidden();
        $this->actingAs($this->cashier)->put('/settings/payments', [])->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/settings/payments')->assertOk();
    }

    public function test_admin_saves_settings_token_is_encrypted_and_change_is_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/settings/payments', [
            'qr_provider' => 'bakong',
            'khqr_account_id' => 'cornermart@aclb',
            'khqr_merchant_name' => 'Corner Mart',
            'khqr_merchant_city' => 'Phnom Penh',
            'qr_manual_confirm' => true,
            'bakong_environment' => 'sandbox',
            'bakong_token' => 'eyJhbGciOi.secret',
        ])->assertSessionHasNoErrors();

        $this->assertNotSame('eyJhbGciOi.secret', Setting::get(PaymentSettings::BAKONG_TOKEN));
        $this->assertSame('eyJhbGciOi.secret', PaymentSettings::bakongToken());
        $this->assertSame('sandbox', PaymentSettings::bakongEnvironment());

        $entry = Activity::where('event', 'payment_settings')->firstOrFail();
        $this->assertStringNotContainsString('secret', json_encode($entry->properties));

        // Blank token on a later save keeps the saved one.
        $this->actingAs($admin)->put('/settings/payments', [
            'qr_provider' => 'bakong',
            'khqr_account_id' => 'cornermart@aclb',
            'bakong_environment' => 'sandbox',
            'bakong_token' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame('eyJhbGciOi.secret', PaymentSettings::bakongToken());
    }

    public function test_bakong_cannot_be_chosen_without_account_and_token(): void
    {
        $this->actingAs(User::factory()->admin()->create())->put('/settings/payments', [
            'qr_provider' => 'bakong',
            'bakong_environment' => 'production',
        ])->assertSessionHasErrors(['khqr_account_id', 'bakong_token']);
    }

    public function test_khmer_script_is_refused_in_the_qr_name(): void
    {
        $this->actingAs(User::factory()->admin()->create())->put('/settings/payments', [
            'qr_provider' => 'manual',
            'khqr_account_id' => 'cornermart@aclb',
            'khqr_merchant_name' => 'ហាងលក់',
            'bakong_environment' => 'production',
        ])->assertSessionHasErrors('khqr_merchant_name');
    }

    public function test_reconcile_asks_about_each_expired_charge_only_once(): void
    {
        $this->useBakong();
        $this->openCharge();

        $this->travel(10)->minutes();
        $this->bakongSays(self::NOT_FOUND);
        $this->artisan('payments:reconcile-qr');
        $this->artisan('payments:reconcile-qr');

        Http::assertSentCount(1);
    }

    public function test_checks_stop_at_the_daily_limit(): void
    {
        config(['payments.bakong.daily_limit' => 1]);
        $this->useBakong();
        $charge = $this->openCharge();

        $this->bakongSays(self::NOT_FOUND);
        $this->getJson("/pos/data/qr/charges/{$charge['id']}")->assertJsonPath('notice', null);

        $this->travel(9)->seconds();
        $this->getJson("/pos/data/qr/charges/{$charge['id']}")
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('notice', fn ($n) => str_contains($n, 'limit'));

        Http::assertSentCount(1);
    }

    public function test_connection_test_uses_the_saved_token(): void
    {
        $this->useBakong();
        $this->bakongAnswers([self::NOT_FOUND, 200], [[], 401]);

        $this->actingAs(User::factory()->admin()->create())
            ->post('/settings/payments/test')
            ->assertSessionHas('success');

        $this->post('/settings/payments/test')->assertSessionHas('error');
    }
}
