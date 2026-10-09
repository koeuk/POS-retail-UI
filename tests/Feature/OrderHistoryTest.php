<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->manager = User::factory()->manager($this->store)->create();
    }

    private function sale(array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'client_uuid' => (string) Str::uuid(),
            'order_no' => 'NO-'.Str::random(8),
            'store_id' => $this->store->id,
            'register_id' => null,
            'cashier_id' => $this->manager->id,
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

        $order->items()->create([
            'product_id' => Product::factory()->create()->id,
            'product_name' => 'Test Item',
            'qty' => 1,
            'unit_price' => '10.00',
            'discount' => '0.00',
            'subtotal' => '10.00',
        ]);

        $order->payments()->create(['method' => 'cash', 'amount' => '11.00']);

        return $order;
    }

    /* ------------------------------------------------------------------ */
    /* Access */
    /* ------------------------------------------------------------------ */

    /**
     * The security-relevant one: a manager must not be able to read another
     * store's takings by guessing an id in the URL.
     */
    public function test_a_manager_cannot_open_another_stores_order(): void
    {
        $other = Store::factory()->create();
        $foreign = $this->sale(['store_id' => $other->id]);

        $this->actingAs($this->manager)
            ->get(route('orders.show', $foreign))
            ->assertNotFound();
    }

    public function test_a_manager_only_lists_their_own_stores_orders(): void
    {
        $other = Store::factory()->create();

        $mine = $this->sale(['order_no' => 'MINE-1']);
        $this->sale(['order_no' => 'THEIRS-1', 'store_id' => $other->id]);

        $this->actingAs($this->manager)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Orders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $mine->id)
            );

        // An admin has no store binding and sees both.
        $this->actingAs($this->admin)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 2));
    }

    public function test_a_cashier_cannot_reach_order_history(): void
    {
        $cashier = User::factory()->cashier($this->store)->create();

        $this->actingAs($cashier)->get(route('orders.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('orders.show', $this->sale()))->assertForbidden();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('orders.index'))->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------ */
    /* Listing */
    /* ------------------------------------------------------------------ */

    public function test_orders_can_be_searched_by_number_cashier_and_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'Ada Lovelace']);

        $byNumber = $this->sale(['order_no' => 'FINDME-99']);
        $byCustomer = $this->sale(['customer_id' => $customer->id]);
        $this->sale(['order_no' => 'OTHER-1']);

        $this->actingAs($this->admin)
            ->get(route('orders.index', ['filter' => ['search' => 'FINDME']]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('orders.data', 1)->where('orders.data.0.id', $byNumber->id));

        $this->actingAs($this->admin)
            ->get(route('orders.index', ['filter' => ['search' => 'Ada']]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('orders.data', 1)->where('orders.data.0.id', $byCustomer->id));

        $this->actingAs($this->admin)
            ->get(route('orders.index', ['filter' => ['search' => $this->manager->name]]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('orders.data', 3));
    }

    public function test_orders_can_be_filtered_by_status_and_payment_method(): void
    {
        $this->sale();
        $refunded = $this->sale(['status' => OrderStatus::Refunded]);

        $card = $this->sale();
        $card->payments()->delete();
        $card->payments()->create(['method' => 'card', 'amount' => '11.00']);

        $this->actingAs($this->admin)
            ->get(route('orders.index', ['filter' => ['status' => 'refunded']]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('orders.data', 1)->where('orders.data.0.id', $refunded->id));

        $this->actingAs($this->admin)
            ->get(route('orders.index', ['filter' => ['method' => 'card']]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('orders.data', 1)->where('orders.data.0.id', $card->id));
    }

    /**
     * The list is ordered by when the sale happened, not when the row landed.
     * A sale synced late must not jump to the top of today's list.
     */
    public function test_the_list_is_ordered_by_when_the_sale_happened(): void
    {
        $old = $this->sale(['order_no' => 'OLD', 'created_offline_at' => now()->subDays(5)]);
        $recent = $this->sale(['order_no' => 'RECENT', 'created_offline_at' => now()->subMinutes(5)]);

        $this->actingAs($this->admin)
            ->get(route('orders.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('orders.data.0.id', $recent->id)
                ->where('orders.data.1.id', $old->id)
            );
    }

    /* ------------------------------------------------------------------ */
    /* Detail */
    /* ------------------------------------------------------------------ */

    public function test_the_detail_page_carries_items_payments_and_receipt_settings(): void
    {
        $order = $this->sale(['created_offline_at' => now()->subHours(3)]);

        $this->actingAs($this->admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Orders/Show')
                ->where('order.order_no', $order->order_no)
                ->has('order.items', 1)
                ->where('order.items.0.product_name', 'Test Item')
                ->has('order.payments', 1)
                ->where('order.payments.0.method', 'cash')
                ->has('settings.receipt_header')
                ->has('settings.currency.symbol')
            );
    }
}
