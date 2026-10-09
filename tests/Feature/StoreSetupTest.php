<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Register;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreSetupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Creating a product seeds a stock row per store. The mirror case was
     * missing: a store created after the catalogue already existed had no
     * rows at all, so its POS showed zero for every product and its shelves
     * never appeared in the low-stock report.
     */
    public function test_a_new_store_gets_a_stock_row_for_every_existing_product(): void
    {
        Product::factory()->count(3)->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('stores.store'), ['name' => 'Second Branch'])
            ->assertRedirect();

        $store = Store::where('name', 'Second Branch')->firstOrFail();

        $this->assertSame(3, Stock::where('store_id', $store->id)->count());

        // Zero, not a copy of another store's count — goods are received
        // through an inventory movement, not by creating a store.
        $this->assertSame(0, (int) Stock::where('store_id', $store->id)->sum('qty'));
    }

    public function test_creating_a_store_with_no_products_yet_is_fine(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('stores.store'), ['name' => 'Empty Branch'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('stores', ['name' => 'Empty Branch']);
    }

    /* ------------------------------------------------------------------ */
    /* Deleting */
    /* ------------------------------------------------------------------ */

    /**
     * orders.store_id is restrictOnDelete, so the database would refuse this
     * anyway — the guard exists so an operator gets a sentence instead of a
     * 500, and so sales history can never lose the shop it belongs to.
     */
    public function test_a_store_with_sales_history_cannot_be_deleted(): void
    {
        $keep = Store::factory()->create();
        $doomed = Store::factory()->create();
        $admin = User::factory()->admin()->create();

        Order::create([
            'client_uuid' => (string) Str::uuid(),
            'order_no' => 'KEEP-1',
            'store_id' => $doomed->id,
            'cashier_id' => $admin->id,
            'subtotal' => '1.00', 'discount_amount' => '0.00',
            'total' => '1.00', 'paid_amount' => '1.00', 'change_amount' => '0.00',
            'status' => OrderStatus::Completed,
        ]);

        $this->actingAs($admin)
            ->delete(route('stores.destroy', $doomed))
            ->assertSessionHasErrors('store');

        $this->assertDatabaseHas('stores', ['id' => $doomed->id]);
        $this->assertSame(2, Store::count());
        $this->assertNotNull($keep);
    }

    /** A cashier with no store cannot open the POS, so never orphan one. */
    public function test_a_store_with_staff_cannot_be_deleted(): void
    {
        Store::factory()->create();
        $doomed = Store::factory()->create();
        User::factory()->cashier($doomed)->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('stores.destroy', $doomed))
            ->assertSessionHasErrors('store');

        $this->assertDatabaseHas('stores', ['id' => $doomed->id]);
    }

    public function test_the_last_store_cannot_be_deleted(): void
    {
        $only = Store::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('stores.destroy', $only))
            ->assertSessionHasErrors('store');

        $this->assertSame(1, Store::count());
    }

    /** An unused store goes, and takes its stock rows and registers with it. */
    public function test_an_unused_store_is_deleted_with_its_stock_and_registers(): void
    {
        Store::factory()->create();
        $doomed = Store::factory()->create();
        Register::factory()->create(['store_id' => $doomed->id]);
        Stock::create(['product_id' => Product::factory()->create()->id, 'store_id' => $doomed->id, 'qty' => 0]);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('stores.destroy', $doomed))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('stores', ['id' => $doomed->id]);
        $this->assertSame(0, Stock::where('store_id', $doomed->id)->count());
        $this->assertSame(0, Register::where('store_id', $doomed->id)->count());
    }

    public function test_a_manager_cannot_delete_a_store(): void
    {
        Store::factory()->create();
        $doomed = Store::factory()->create();

        $this->actingAs(User::factory()->manager($doomed)->create())
            ->delete(route('stores.destroy', $doomed))
            ->assertForbidden();

        $this->assertDatabaseHas('stores', ['id' => $doomed->id]);
    }

    /** Both directions must hold: a product added later reaches every store. */
    public function test_a_product_created_later_reaches_every_store(): void
    {
        $a = Store::factory()->create();
        $b = Store::factory()->create();
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $this->actingAs($admin)->post(route('products.store'), [
            'category_id' => $category->id,
            'name' => 'Late Arrival',
            'sku' => 'LATE-1',
            'cost_price' => '1.00',
            'sell_price' => '2.00',
            'unit' => 'pcs',
            'opening_qty' => 7,
        ])->assertRedirect();

        $product = Product::where('sku', 'LATE-1')->firstOrFail();

        foreach ([$a, $b] as $store) {
            $this->assertDatabaseHas('stocks', [
                'product_id' => $product->id,
                'store_id' => $store->id,
                'qty' => 7,
            ]);
        }
    }
}
