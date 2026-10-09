<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Register;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The token API is the same app through a different door: same accounts,
 * same permission gates, same sync contract. These tests pin exactly that.
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $admin;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        Register::factory()->create(['store_id' => $this->store->id]);
        $this->admin = User::factory()->admin()->create();
        $this->cashier = User::factory()->cashier($this->store)->create();
    }

    private function token(User $user): string
    {
        /*
         * Laravel's test client caches the resolved guard between requests in
         * one test, so a second request would silently reuse the first
         * request's user — token revocation and permission changes would
         * never be re-read. Forgetting the guards makes every request
         * authenticate for real, like production does.
         */
        $this->app['auth']->forgetGuards();

        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_token_is_issued_for_valid_credentials_and_refused_otherwise(): void
    {
        $issued = $this->postJson('/api/v1/auth/token', [
            'email' => $this->admin->email,
            'password' => 'password',
            'device_name' => 'warehouse-laptop',
        ])->assertCreated()->json();

        $this->assertNotEmpty($issued['token']);
        $this->assertTrue($issued['can']['reports'], 'the resolved permission map rides along');

        $this->postJson('/api/v1/auth/token', [
            'email' => $this->admin->email,
            'password' => 'wrong',
            'device_name' => 'x',
        ])->assertUnprocessable();
    }

    public function test_a_deactivated_account_gets_no_token_and_a_dead_token(): void
    {
        $token = $this->token($this->admin);
        $this->admin->update(['is_active' => false]);

        // The existing token dies on its next request — no session needed.
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();

        $this->postJson('/api/v1/auth/token', [
            'email' => $this->admin->email, 'password' => 'password', 'device_name' => 'x',
        ])->assertUnprocessable();
    }

    public function test_the_permission_gate_answers_for_tokens_exactly_as_for_sessions(): void
    {
        // A cashier's default is POS only — reports is a closed door...
        $this->withToken($this->token($this->cashier))
            ->getJson('/api/v1/reports/summary')->assertForbidden();

        // ...until someone grants it on the Staff screen. Same override, same token.
        $this->cashier->update(['permissions' => ['reports' => true]]);

        $this->withToken($this->token($this->cashier))
            ->getJson('/api/v1/reports/summary')->assertOk()
            ->assertJsonStructure(['totals' => ['orders', 'sales', 'items', 'basket'], 'by_day', 'by_product', 'by_payment']);
    }

    public function test_products_list_answers_with_the_shared_filter_grammar(): void
    {
        $cat = Category::factory()->create();
        Product::factory()->create(['name' => 'Cola 330ml', 'category_id' => $cat->id]);
        Product::factory()->create(['name' => 'Soap bar', 'category_id' => $cat->id]);

        $this->withToken($this->token($this->admin))
            ->getJson('/api/v1/products?filter[search]=Cola')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cola 330ml');
    }

    public function test_orders_can_be_synced_with_a_token_and_are_idempotent(): void
    {
        $product = Product::factory()->create(['sell_price' => '10.00']);
        Stock::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'qty' => 50]);

        $payload = ['orders' => [[
            'client_uuid' => (string) Str::uuid(),
            'store_id' => $this->store->id,
            'created_offline_at' => now()->toIso8601String(),
            'discount_amount' => '0.00',
            'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'qty' => 2, 'unit_price' => '10.00', 'discount' => '0.00']],
            'payments' => [['method' => 'cash', 'amount' => '20.00', 'reference_no' => null]],
        ]]];

        $first = $this->withToken($this->token($this->admin))
            ->postJson('/api/v1/orders/sync', $payload)->assertOk()->json('results.0');
        $this->assertSame('created', $first['status']);

        // The retry collapses into the original — the token door keeps the contract.
        $second = $this->withToken($this->token($this->admin))
            ->postJson('/api/v1/orders/sync', $payload)->assertOk()->json('results.0');
        $this->assertSame($first['order_id'], $second['order_id']);
        $this->assertSame(48, Stock::first()->qty, 'stock moved exactly once');
    }

    public function test_a_debt_can_be_settled_over_the_api(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['sell_price' => '10.00']);
        Stock::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'qty' => 50]);

        $this->withToken($this->token($this->admin))->postJson('/api/v1/orders/sync', ['orders' => [[
            'client_uuid' => (string) Str::uuid(),
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'sale_type' => 'debt',
            'created_offline_at' => now()->toIso8601String(),
            'discount_amount' => '0.00',
            'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'qty' => 2, 'unit_price' => '10.00', 'discount' => '0.00']],
            'payments' => [['method' => 'cash', 'amount' => '0.00', 'reference_no' => null]],
        ]]])->assertOk();

        $debt = $this->withToken($this->token($this->admin))
            ->getJson('/api/v1/debts')->assertOk()->json('data.0');
        $this->assertSame('20.00', $debt['total']);

        $settled = $this->withToken($this->token($this->admin))
            ->postJson("/api/v1/debts/{$debt['uuid']}/settle", ['amount' => '20.00', 'method' => 'cash'])
            ->assertOk()->json();
        $this->assertTrue($settled['settled']);

        // Paying more than is owed is refused, same as the web screen.
        $this->withToken($this->token($this->admin))
            ->postJson("/api/v1/debts/{$debt['uuid']}/settle", ['amount' => '1.00', 'method' => 'cash'])
            ->assertUnprocessable();
    }

    public function test_a_cashier_token_only_sees_its_own_store(): void
    {
        $other = Store::factory()->create();
        $product = Product::factory()->create();
        Stock::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'qty' => 5]);
        Stock::create(['product_id' => $product->id, 'store_id' => $other->id, 'qty' => 9]);

        $this->cashier->update(['permissions' => ['inventory' => true]]);

        $rows = $this->withToken($this->token($this->cashier))
            ->getJson('/api/v1/inventory')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->store->id, $rows[0]['store_id']);
    }

    public function test_revoking_the_token_shuts_the_door(): void
    {
        $token = $this->token($this->admin);

        $this->withToken($token)->deleteJson('/api/v1/auth/token')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }
}
