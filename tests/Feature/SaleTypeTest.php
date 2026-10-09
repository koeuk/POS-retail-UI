<?php

namespace Tests\Feature;

use App\Enums\SaleType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Register;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Services\SalesReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SaleTypeTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $cashier;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        Register::factory()->create(['store_id' => $this->store->id]);
        $this->cashier = User::factory()->cashier($this->store)->create();
        $this->admin = User::factory()->admin()->create();
        $this->product = Product::factory()->create(['sell_price' => '10.00']);
        Stock::create(['product_id' => $this->product->id, 'store_id' => $this->store->id, 'qty' => 20]);
    }

    private function sync(array $overrides = [], ?int $customerId = null): TestResponse
    {
        return $this->actingAs($this->cashier)->postJson(route('pos.data.orders.sync'), ['orders' => [array_merge([
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'created_offline_at' => now()->toIso8601String(),
            'discount_amount' => '0.00',
            'items' => [['product_id' => $this->product->id, 'product_name' => $this->product->name, 'qty' => 2, 'unit_price' => '10.00', 'discount' => '0.00']],
            'payments' => [['method' => 'cash', 'amount' => '20.00', 'reference_no' => null]],
        ], $overrides)]]);
    }

    /** A debt sale; the payment sent along is the deposit taken at the till. */
    private function debt(Customer $customer, string $deposit = '0.00'): TestResponse
    {
        return $this->sync([
            'sale_type' => 'debt',
            'payments' => [['method' => 'cash', 'amount' => $deposit, 'reference_no' => null]],
        ], $customer->id);
    }

    private function stock(): int
    {
        return (int) Stock::where('product_id', $this->product->id)->where('store_id', $this->store->id)->value('qty');
    }

    /* ------------------------------------------------------------------ */
    /* Myself: leaves the shelf, never touches the till */
    /* ------------------------------------------------------------------ */

    public function test_a_myself_sale_moves_stock_but_is_not_revenue(): void
    {
        $this->sync(['sale_type' => 'myself'])->assertOk()->assertJsonPath('results.0.status', 'created');

        $this->assertSame(18, $this->stock(), 'the goods really left the shelf');

        $summary = (new SalesReporter($this->store->id))->summaryFor(SalesReporter::businessNow()->startOfDay());
        $this->assertSame('0.00', $summary['sales'], 'eating a chocolate bar is not takings');
        $this->assertSame(0, $summary['orders']);
    }

    public function test_a_customer_sale_is_revenue(): void
    {
        $this->sync(['sale_type' => 'customer'])->assertOk();

        $summary = (new SalesReporter($this->store->id))->summaryFor(SalesReporter::businessNow()->startOfDay());
        $this->assertSame('20.00', $summary['sales']);
    }

    public function test_the_type_defaults_to_customer_when_the_till_sends_none(): void
    {
        $this->sync()->assertOk();

        $this->assertSame(SaleType::Customer, Order::firstOrFail()->sale_type);
    }

    /* ------------------------------------------------------------------ */
    /* Debt: revenue AND a receivable, and it needs a name */
    /* ------------------------------------------------------------------ */

    public function test_a_debt_with_no_deposit_is_owed_in_full_and_writes_no_payment_row(): void
    {
        $c = Customer::factory()->create();

        $this->debt($c)->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('20.00', $order->total);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertSame('20.00', $order->outstanding());
        $this->assertSame(0, $order->payments()->count(), 'the till\'s "0.00 cash" is not a payment');
        $this->assertSame(18, $this->stock());

        // It still counts as a sale — the goods were sold, just not yet paid for.
        $this->assertSame('20.00', (new SalesReporter($this->store->id))->summaryFor(SalesReporter::businessNow()->startOfDay())['sales']);
    }

    public function test_a_deposit_on_a_debt_is_recorded_and_the_rest_is_owed(): void
    {
        $c = Customer::factory()->create();

        $this->debt($c, '5.00')->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('5.00', $order->paid_amount, 'the deposit really changed hands');
        $this->assertSame('15.00', $order->outstanding());
        $this->assertSame('0.00', $order->change_amount);
        $this->assertSame(1, $order->payments()->count(), 'the deposit has its ledger row, so settle() can sum it');
        $this->assertSame('5.00', $order->payments()->first()->amount);

        // Settling the remainder closes the debt — the deposit already counted.
        $this->actingAs($this->admin)
            ->post(route('debts.settle', $order), ['amount' => '15.00', 'method' => 'cash'])
            ->assertSessionHasNoErrors();
        $this->assertSame('0.00', $order->fresh()->outstanding());
    }

    public function test_a_deposit_can_never_exceed_the_bill(): void
    {
        $c = Customer::factory()->create();

        // Sync never rejects a completed sale, so an over-payment is capped:
        // the debt is simply born settled rather than stuck in the queue.
        $this->debt($c, '25.00')->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('20.00', $order->paid_amount);
        $this->assertSame('0.00', $order->outstanding());
        $this->assertSame('0.00', $order->change_amount, 'a debt never gives change');
    }

    /** Money nobody can collect is not a debt, it is a loss. */
    public function test_a_debt_without_a_customer_is_rejected(): void
    {
        $this->sync(['sale_type' => 'debt'])->assertStatus(422)->assertJsonValidationErrors('orders.0.customer_id');

        $this->assertSame(0, Order::count());
        $this->assertSame(20, $this->stock(), 'nothing left the shelf');
    }

    public function test_an_unknown_sale_type_is_rejected(): void
    {
        $this->sync(['sale_type' => 'gift'])->assertStatus(422)->assertJsonValidationErrors('orders.0.sale_type');
    }

    /* ------------------------------------------------------------------ */
    /* Settling */
    /* ------------------------------------------------------------------ */

    public function test_a_debt_can_be_paid_off_in_parts_and_is_settled_when_paid_in_full(): void
    {
        $c = Customer::factory()->create(['name' => 'Ada']);
        $this->debt($c)->assertOk();
        $order = Order::firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('debts.settle', $order), ['amount' => '12.00', 'method' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame('12.00', $order->fresh()->paid_amount);
        $this->assertSame('8.00', $order->fresh()->outstanding());

        $this->actingAs($this->admin)
            ->post(route('debts.settle', $order), ['amount' => '8.00', 'method' => 'qr', 'reference_no' => 'QR-1'])
            ->assertSessionHasNoErrors();

        $this->assertSame('20.00', $order->fresh()->paid_amount);
        $this->assertSame('0.00', $order->fresh()->outstanding());
        $this->assertSame(2, $order->payments()->count(), 'each payment is its own ledger row');
    }

    public function test_you_cannot_pay_more_than_is_owed(): void
    {
        $c = Customer::factory()->create();
        $this->debt($c)->assertOk();
        $order = Order::firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('debts.settle', $order), ['amount' => '25.00', 'method' => 'cash'])
            ->assertSessionHasErrors('amount');

        $this->assertSame('0.00', $order->fresh()->paid_amount);
    }

    /** Settling a debt "on credit" would just create another debt. */
    public function test_a_debt_cannot_be_settled_on_credit(): void
    {
        $c = Customer::factory()->create();
        $this->debt($c)->assertOk();

        $this->actingAs($this->admin)
            ->post(route('debts.settle', Order::firstOrFail()), ['amount' => '20.00', 'method' => 'credit'])
            ->assertSessionHasErrors('method');
    }

    /* ------------------------------------------------------------------ */
    /* The two screens */
    /* ------------------------------------------------------------------ */

    public function test_the_debt_screen_lists_what_is_owed_and_sums_it(): void
    {
        $a = Customer::factory()->create(['name' => 'Ada']);
        $b = Customer::factory()->create(['name' => 'Bob']);
        $this->debt($a)->assertOk();
        $this->debt($b)->assertOk();
        $this->sync(['sale_type' => 'customer'])->assertOk(); // must not appear

        $this->actingAs($this->admin)
            ->get(route('debts.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Debts/Index')
                ->has('debts.data', 2)
                ->where('summary.open_count', 2)
                ->where('summary.owed', '40.00')
            );
    }

    /**
     * The details panel reads the items and payments straight off the row,
     * so the list must ship them — an empty panel would look like a debt for
     * nothing, which is the one thing a customer will argue about.
     */
    public function test_each_debt_row_carries_its_items_and_payments(): void
    {
        $c = Customer::factory()->create(['name' => 'Ada']);
        $this->debt($c)->assertOk();
        $order = Order::firstOrFail();
        $this->actingAs($this->admin)->post(route('debts.settle', $order), ['amount' => '5.00', 'method' => 'cash']);

        $this->actingAs($this->admin)
            ->get(route('debts.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('debts.data', 1)
                ->has('debts.data.0.items', 1)
                ->where('debts.data.0.items.0.product_name', $this->product->name)
                ->where('debts.data.0.items.0.qty', 2)
                ->has('debts.data.0.payments', 1)
                ->where('debts.data.0.payments.0.amount', '5.00')
            );
    }

    public function test_the_myself_screen_lists_only_owner_take_outs(): void
    {
        $this->sync(['sale_type' => 'myself'])->assertOk();
        $this->sync(['sale_type' => 'customer'])->assertOk(); // must not appear

        $this->actingAs($this->admin)
            ->get(route('consumption.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Consumption/Index')
                ->has('rows.data', 1)
                ->where('summary.week.count', 1)
                ->where('summary.week.value', '20.00')
                ->where('summary.month.count', 1)
                ->where('summary.month.value', '20.00')
                ->where('summary.year.count', 1)
                ->where('summary.year.value', '20.00')
            );
    }

    public function test_a_cashier_cannot_reach_either_screen(): void
    {
        $this->actingAs($this->cashier)->get(route('debts.index'))->assertForbidden();
        $this->actingAs($this->cashier)->get(route('consumption.index'))->assertForbidden();
    }

    /**
     * Regression: the sync composable sends an explicit allowlist of fields,
     * and sale_type was once left off it — every "myself" sale then landed as
     * a paid customer sale. This pins the wire contract on the server side:
     * whatever the till sends as sale_type is what gets stored.
     */
    public function test_the_sale_type_sent_by_the_till_is_the_one_stored(): void
    {
        $c = Customer::factory()->create();

        $this->sync(['sale_type' => 'myself'])->assertOk();
        $this->debt($c)->assertOk();
        $this->sync(['sale_type' => 'customer'])->assertOk();

        $this->assertSame(
            ['myself', 'debt', 'customer'],
            Order::orderBy('id')->pluck('sale_type')->map(fn ($t) => $t->value)->all(),
        );
    }

    /**
     * The order page must say a debt is still owed — and stop saying it the
     * moment it is paid off. "Still in debt" is a live balance, not a flag.
     */
    public function test_the_order_page_shows_a_debt_only_while_something_is_owed(): void
    {
        $c = Customer::factory()->create(['name' => 'Sok Dara']);
        $this->debt($c)->assertOk();
        $order = Order::firstOrFail();

        // Unpaid: the page carries the full balance.
        $this->actingAs($this->admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.sale_type', 'debt')
                ->where('outstanding', '20.00'));

        // Half paid: the balance follows.
        $this->actingAs($this->admin)->post(route('debts.settle', $order), ['amount' => '12.00', 'method' => 'cash']);
        $this->actingAs($this->admin)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('outstanding', '8.00'));

        // Settled: nothing owed, so nothing to shout about.
        $this->actingAs($this->admin)->post(route('debts.settle', $order), ['amount' => '8.00', 'method' => 'cash']);
        $this->actingAs($this->admin)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('outstanding', '0.00'));
    }

    /** A plain sale never reports a balance, so the debt banner can never appear on it. */
    public function test_a_customer_sale_has_nothing_outstanding(): void
    {
        $this->sync(['sale_type' => 'customer'])->assertOk();

        $this->actingAs($this->admin)
            ->get(route('orders.show', Order::firstOrFail()))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('outstanding', '0.00'));
    }

    /** The till can look up and create the customer a debt needs. */
    public function test_the_till_can_find_and_create_a_customer(): void
    {
        Customer::factory()->create(['name' => 'Ada Lovelace', 'phone' => '012 111 222']);

        $this->actingAs($this->cashier)->getJson(route('pos.data.customers', ['q' => 'Ada']))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Ada Lovelace');

        $this->actingAs($this->cashier)->postJson(route('pos.data.customers.store'), ['name' => 'New Person', 'phone' => null])
            ->assertCreated()->assertJsonPath('name', 'New Person');

        $this->assertDatabaseHas('customers', ['name' => 'New Person']);
    }

    public function test_the_dashboard_shows_the_receivable_and_the_owners_own_take(): void
    {
        $c = Customer::factory()->create();
        $this->debt($c)->assertOk();   // 20.00 out on credit
        $this->sync(['sale_type' => 'myself'])->assertOk();          // 20.00 taken for myself
        $this->sync(['sale_type' => 'customer'])->assertOk();        // ordinary sale — in neither card

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('debts.count', 1)
                ->where('debts.owed', '20.00')
                ->where('myself.week.count', 1)
                ->where('myself.week.value', '20.00')
                ->where('myself.month.value', '20.00')
                ->where('myself.year.value', '20.00')
            );

        // A cashier's dashboard carries neither card nor its numbers' gate.
        $this->actingAs($this->cashier)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canSeeReports', false));
    }
}
