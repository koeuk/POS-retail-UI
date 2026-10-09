<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminCrudTest extends TestCase
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

    /* ------------------------------------------------------------------ */
    /* Products */
    /* ------------------------------------------------------------------ */

    public function test_admin_can_create_a_product_with_opening_stock(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $category->id,
            'name' => 'Test Cola',
            'sku' => 'SKU-TEST-1',
            'barcode' => '1234567890123',
            'cost_price' => '0.50',
            'sell_price' => '1.20',
            'unit' => 'can',
            'track_stock' => true,
            'is_active' => true,
            'opening_qty' => 25,
            'low_stock_threshold' => 5,
        ])->assertRedirect(route('products.index'));

        $product = Product::where('sku', 'SKU-TEST-1')->firstOrFail();

        // A stock row must exist for every store, so the POS feed always has
        // one to read even when the opening quantity is zero.
        $this->assertDatabaseHas('stocks', [
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'qty' => 25,
            'low_stock_threshold' => 5,
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $product->id,
            'type' => 'restock',
            'qty_change' => 25,
        ]);
    }

    /** Image and gallery arrive as pasted links — no file crosses the wire. */
    public function test_a_product_can_take_its_image_and_gallery_by_link(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $category->id,
            'name' => 'Linked Cola',
            'sku' => 'SKU-LINK-1',
            'cost_price' => '0.50',
            'sell_price' => '1.20',
            'unit' => 'can',
            'track_stock' => true,
            'is_active' => true,
            'image_url' => 'https://example.com/photos/cola.jpg',
            'gallery_urls' => [
                'https://example.com/photos/cola-front.jpg',
                'https://example.com/photos/cola-back.jpg',
            ],
        ])->assertRedirect(route('products.index'));

        $product = Product::where('sku', 'SKU-LINK-1')->firstOrFail();

        // Stored verbatim, never copied into /storage — the frontend renders
        // http(s) sources directly (see imageSrc in resources/js/lib/utils.ts).
        $this->assertSame('https://example.com/photos/cola.jpg', $product->image);
        $this->assertSame([
            'https://example.com/photos/cola-front.jpg',
            'https://example.com/photos/cola-back.jpg',
        ], $product->gallery);

        // And a link that is not http(s) is refused, not stored.
        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $category->id,
            'name' => 'Bad Link',
            'sku' => 'SKU-LINK-2',
            'cost_price' => '0.50',
            'sell_price' => '1.20',
            'unit' => 'can',
            'image_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('image_url');
    }

    public function test_product_sku_must_be_unique(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['sku' => 'DUPE-1', 'category_id' => $category->id]);

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $category->id,
            'name' => 'Another',
            'sku' => 'DUPE-1',
            'cost_price' => '1.00',
            'sell_price' => '2.00',
            'unit' => 'pcs',
        ])->assertSessionHasErrors('sku');
    }

    public function test_admin_can_update_and_delete_a_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)->put(route('products.update', $product), [
            'category_id' => $product->category_id,
            'name' => 'Renamed',
            'sku' => $product->sku,
            'cost_price' => '2.00',
            'sell_price' => '5.00',
            'unit' => 'pcs',
            'track_stock' => true,
            'is_active' => true,
        ])->assertRedirect(route('products.index'));

        $this->assertSame('Renamed', $product->fresh()->name);

        $this->actingAs($this->admin)
            ->delete(route('products.destroy', $product))
            ->assertRedirect();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    /* ------------------------------------------------------------------ */
    /* Categories */
    /* ------------------------------------------------------------------ */

    public function test_admin_can_create_and_rename_a_category(): void
    {
        $this->actingAs($this->admin)
            ->post(route('categories.store'), ['name' => 'Frozen'])
            ->assertRedirect();

        $category = Category::where('name', 'Frozen')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('categories.update', $category), ['name' => 'Frozen Goods'])
            ->assertRedirect();

        $this->assertSame('Frozen Goods', $category->fresh()->name);
    }

    public function test_an_empty_category_can_be_deleted(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $category))
            ->assertRedirect();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_category_holding_products_cannot_be_deleted(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $category))
            ->assertSessionHasErrors('category');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    /* ------------------------------------------------------------------ */
    /* Customers */
    /* ------------------------------------------------------------------ */

    public function test_admin_can_create_and_delete_a_customer(): void
    {
        $this->actingAs($this->admin)
            ->post(route('customers.store'), ['name' => 'Walk-in Jane', 'phone' => '012345678'])
            ->assertRedirect();

        $customer = Customer::where('name', 'Walk-in Jane')->firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('customers.destroy', $customer))
            ->assertRedirect();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    /* ------------------------------------------------------------------ */
    /* Staff */
    /* ------------------------------------------------------------------ */

    /**
     * The single most important validation rule in the admin: a cashier with
     * no store cannot resolve stock rows, so /pos breaks the moment they open it.
     */
    public function test_a_cashier_cannot_be_created_without_a_store(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'Storeless',
            'email' => 'storeless@test.local',
            'password' => 'Str0ng-Password!',
            'password_confirmation' => 'Str0ng-Password!',
            'role' => Role::Cashier->value,
            'store_id' => null,
            'is_active' => true,
        ])->assertSessionHasErrors('store_id');

        $this->assertDatabaseMissing('users', ['email' => 'storeless@test.local']);
    }

    public function test_an_admin_may_be_created_without_a_store(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'Second Admin',
            'email' => 'second@test.local',
            'password' => 'Str0ng-Password!',
            'password_confirmation' => 'Str0ng-Password!',
            'role' => Role::Admin->value,
            'store_id' => null,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'second@test.local', 'role' => 'admin']);
    }

    public function test_an_admin_cannot_delete_or_demote_themselves(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $this->admin))
            ->assertSessionHasErrors('user');

        $this->actingAs($this->admin)->put(route('users.update', $this->admin), [
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'role' => Role::Cashier->value,
            'store_id' => $this->store->id,
            'is_active' => false,
        ])->assertRedirect();

        // Role and active flag are pinned server-side for your own account.
        $this->assertTrue($this->admin->fresh()->isAdmin());
        $this->assertTrue($this->admin->fresh()->is_active);
    }

    /* ------------------------------------------------------------------ */
    /* Product view */
    /* ------------------------------------------------------------------ */

    public function test_the_product_view_page_shows_stock_movements_and_sales(): void
    {
        $product = Product::factory()->create(['sell_price' => '10.00']);

        Stock::create([
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'qty' => 12,
            'low_stock_threshold' => 3,
        ]);

        $this->actingAs($this->admin)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Show')
                ->where('product.id', $product->id)
                ->has('stocks', 1)
                ->where('stocks.0.qty', 12)
                ->has('movements')
                ->has('sales')
            );
    }

    /* ------------------------------------------------------------------ */
    /* Authorisation */
    /* ------------------------------------------------------------------ */

    public function test_manager_can_reach_the_catalogue_but_not_staff(): void
    {
        $manager = User::factory()->manager($this->store)->create();

        $this->actingAs($manager)->get(route('products.index'))->assertOk();
        $this->actingAs($manager)->get(route('categories.index'))->assertOk();
        $this->actingAs($manager)->get(route('customers.index'))->assertOk();
        $this->actingAs($manager)->get(route('users.index'))->assertForbidden();
    }

    public function test_cashier_is_locked_out_of_every_admin_screen(): void
    {
        $cashier = User::factory()->cashier($this->store)->create();

        foreach (['products.index', 'categories.index', 'customers.index', 'users.index', 'stores.index'] as $name) {
            $this->actingAs($cashier)->get(route($name))->assertForbidden();
        }
    }
}
