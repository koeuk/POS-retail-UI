<?php

namespace Tests\Feature;

use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $admin;

    private Product $product;

    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->product = Product::factory()->create(['unit' => 'pcs']);
        $this->stock = Stock::create([
            'product_id' => $this->product->id,
            'store_id' => $this->store->id,
            'qty' => 10,
            'low_stock_threshold' => 5,
        ]);
    }

    private function move(array $payload)
    {
        return $this->actingAs($this->admin)
            ->post(route('inventory.store'), array_merge(['stock_id' => $this->stock->id], $payload));
    }

    /* ------------------------------------------------------------------ */
    /* Movements */
    /* ------------------------------------------------------------------ */

    public function test_restocking_adds_to_it_and_logs_a_restock(): void
    {
        $this->move(['mode' => 'restock', 'quantity' => 15, 'note' => 'Supplier delivery'])->assertRedirect();

        $this->assertSame(25, $this->stock->fresh()->qty);
        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'store_id' => $this->store->id,
            'type' => 'restock',
            'qty_change' => 15,
            'note' => 'Supplier delivery',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_removing_stock_subtracts_and_logs_an_adjustment(): void
    {
        $this->move(['mode' => 'remove', 'quantity' => 4, 'note' => 'Damaged'])->assertRedirect();

        $this->assertSame(6, $this->stock->fresh()->qty);
        $this->assertDatabaseHas('inventory_logs', ['type' => 'adjustment', 'qty_change' => -4]);
    }

    /**
     * A count is absolute, not a delta — the operator types what is on the
     * shelf and the ledger records whatever reconciles the books to it.
     */
    public function test_a_count_records_the_difference_not_the_number_typed(): void
    {
        $this->move(['mode' => 'count', 'quantity' => 7])->assertRedirect();

        $this->assertSame(7, $this->stock->fresh()->qty);
        $this->assertDatabaseHas('inventory_logs', ['type' => 'adjustment', 'qty_change' => -3]);
    }

    public function test_a_count_can_correct_upwards_too(): void
    {
        $this->move(['mode' => 'count', 'quantity' => 18])->assertRedirect();

        $this->assertSame(18, $this->stock->fresh()->qty);
        $this->assertDatabaseHas('inventory_logs', ['qty_change' => 8]);
    }

    /** A count that matches the books is not a movement and must not log one. */
    public function test_a_count_that_changes_nothing_writes_no_movement(): void
    {
        $this->move(['mode' => 'count', 'quantity' => 10])->assertRedirect();

        $this->assertSame(10, $this->stock->fresh()->qty);
        $this->assertSame(0, InventoryLog::count());
    }

    /** A count is how an oversold row gets reconciled back to reality. */
    public function test_a_count_reconciles_an_oversold_row(): void
    {
        $this->stock->update(['qty' => -6]);

        $this->move(['mode' => 'count', 'quantity' => 0])->assertRedirect();

        $this->assertSame(0, $this->stock->fresh()->qty);
        $this->assertDatabaseHas('inventory_logs', ['qty_change' => 6]);
    }

    public function test_a_customer_return_adds_stock_back(): void
    {
        $this->move(['mode' => 'return', 'quantity' => 2])->assertRedirect();

        $this->assertSame(12, $this->stock->fresh()->qty);
        $this->assertDatabaseHas('inventory_logs', ['type' => 'return', 'qty_change' => 2]);
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $this->move(['mode' => 'teleport', 'quantity' => 5])->assertSessionHasErrors('mode');

        $this->assertSame(10, $this->stock->fresh()->qty);
    }

    /* ------------------------------------------------------------------ */
    /* Threshold */
    /* ------------------------------------------------------------------ */

    public function test_the_threshold_can_be_set_and_cleared_without_a_movement(): void
    {
        $this->actingAs($this->admin)
            ->put(route('inventory.threshold'), ['stock_id' => $this->stock->id, 'low_stock_threshold' => 20])
            ->assertRedirect();

        $this->assertSame(20, $this->stock->fresh()->low_stock_threshold);

        $this->actingAs($this->admin)
            ->put(route('inventory.threshold'), ['stock_id' => $this->stock->id, 'low_stock_threshold' => null])
            ->assertRedirect();

        $this->assertNull($this->stock->fresh()->low_stock_threshold);

        // Changing an alert level is a setting, not stock moving.
        $this->assertSame(0, InventoryLog::count());
    }

    /* ------------------------------------------------------------------ */
    /* Access */
    /* ------------------------------------------------------------------ */

    /** A manager must not be able to move another store's stock by id. */
    public function test_a_manager_cannot_move_another_stores_stock(): void
    {
        $other = Store::factory()->create();
        $manager = User::factory()->manager($other)->create();

        $this->actingAs($manager)
            ->post(route('inventory.store'), ['stock_id' => $this->stock->id, 'mode' => 'restock', 'quantity' => 99])
            ->assertNotFound();

        $this->assertSame(10, $this->stock->fresh()->qty);
    }

    /* ------------------------------------------------------------------ */
    /* Product picker */
    /* ------------------------------------------------------------------ */

    public function test_the_lookup_returns_products_with_their_current_stock(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('inventory.lookup', ['q' => $this->product->name]));

        $response->assertOk()
            ->assertJsonPath('results.0.id', $this->stock->id)
            ->assertJsonPath('results.0.qty', 10)
            ->assertJsonPath('results.0.product.name', $this->product->name)
            ->assertJsonPath('results.0.product.unit', 'pcs')
            ->assertJsonPath('results.0.store.name', $this->store->name);
    }

    public function test_the_lookup_matches_on_sku_and_ignores_inactive_products(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('inventory.lookup', ['q' => $this->product->sku]))
            ->assertOk()
            ->assertJsonCount(1, 'results');

        $this->product->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->getJson(route('inventory.lookup', ['q' => $this->product->sku]))
            ->assertOk()
            ->assertJsonCount(0, 'results');
    }

    /** The picker must not become a way to read another store's shelves. */
    public function test_the_lookup_is_scoped_to_the_users_own_store(): void
    {
        $other = Store::factory()->create();
        $otherProduct = Product::factory()->create(['name' => 'Elsewhere Only']);
        Stock::create(['product_id' => $otherProduct->id, 'store_id' => $other->id, 'qty' => 4]);

        $manager = User::factory()->create(['role' => 'manager', 'store_id' => $this->store->id]);

        $this->actingAs($manager)
            ->getJson(route('inventory.lookup', ['q' => 'Elsewhere']))
            ->assertOk()
            ->assertJsonCount(0, 'results');
    }

    public function test_a_cashier_cannot_reach_the_lookup(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'store_id' => $this->store->id]);

        $this->actingAs($cashier)
            ->getJson(route('inventory.lookup'))
            ->assertForbidden();
    }

    public function test_a_cashier_cannot_reach_inventory(): void
    {
        $cashier = User::factory()->cashier($this->store)->create();

        $this->actingAs($cashier)->get(route('inventory.index'))->assertForbidden();
        $this->actingAs($cashier)
            ->post(route('inventory.store'), ['stock_id' => $this->stock->id, 'mode' => 'restock', 'quantity' => 1])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Listing */
    /* ------------------------------------------------------------------ */

    /**
     * The list can be ordered by stock in either direction or by name. The
     * value is whitelisted: anything unrecognised falls back to lowest-first
     * rather than reaching orderBy() raw.
     */
    public function test_the_index_ships_pack_sizes_so_stock_can_be_shown_in_cases(): void
    {
        $base = Product::factory()->create(['name' => 'Cola can']);
        Product::factory()->create(['name' => 'Case of 12', 'parent_product_id' => $base->id, 'units_per_pack' => 12]);
        Stock::create(['product_id' => $base->id, 'store_id' => $this->store->id, 'qty' => 97]);

        $this->actingAs($this->admin)
            ->get(route('inventory.index', ['filter' => ['search' => 'Cola can']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stocks.data.0.qty', 97)
                ->where('stocks.data.0.product.packs.0.name', 'Case of 12')
                ->where('stocks.data.0.product.packs.0.units_per_pack', 12)
            );
    }

    public function test_the_index_ships_the_case_size_so_stock_can_be_counted_in_cases(): void
    {
        $noodles = Product::factory()->create(['name' => 'Instant noodles', 'case_size' => 80]);
        Stock::create(['product_id' => $noodles->id, 'store_id' => $this->store->id, 'qty' => 1462]);

        $this->actingAs($this->admin)
            ->get(route('inventory.index', ['filter' => ['search' => 'Instant noodles']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stocks.data.0.qty', 1462)
                ->where('stocks.data.0.product.case_size', 80) // 18 cases + 22 loose, worked out on the page
            );
    }

    public function test_the_index_can_be_sorted_by_stock_or_name(): void
    {
        // setUp already made one product at qty 10. Add a clear spread.
        $lo = Product::factory()->create(['name' => 'Aardvark Snacks']);
        $hi = Product::factory()->create(['name' => 'Zebra Crisps']);
        Stock::create(['product_id' => $lo->id, 'store_id' => $this->store->id, 'qty' => 1]);
        Stock::create(['product_id' => $hi->id, 'store_id' => $this->store->id, 'qty' => 99]);

        $qtys = fn (string $sort) => collect($this->actingAs($this->admin)
            ->get(route('inventory.index', ['sort' => $sort]))
            ->assertOk()
            ->viewData('page')['props']['stocks']['data'])->pluck('qty')->all();

        $this->assertSame([1, 10, 99], $qtys('qty'), 'lowest stock first');
        $this->assertSame([99, 10, 1], $qtys('-qty'), 'highest stock first');

        // Spatie's query builder refuses a sort that was never allowed.
        $this->actingAs($this->admin)->get(route('inventory.index', ['sort' => 'bogus']))->assertStatus(400);

        $names = collect($this->actingAs($this->admin)
            ->get(route('inventory.index', ['sort' => 'name']))
            ->viewData('page')['props']['stocks']['data'])->pluck('product.name')->all();
        $this->assertSame('Aardvark Snacks', $names[0]);
        $this->assertSame('Zebra Crisps', $names[2]);
    }

    public function test_the_index_summarises_and_filters_by_state(): void
    {
        $low = Stock::create(['product_id' => Product::factory()->create()->id, 'store_id' => $this->store->id, 'qty' => 2, 'low_stock_threshold' => 5]);
        $oversold = Stock::create(['product_id' => Product::factory()->create()->id, 'store_id' => $this->store->id, 'qty' => -3]);
        Stock::create(['product_id' => Product::factory()->create()->id, 'store_id' => $this->store->id, 'qty' => 0]);

        $this->actingAs($this->admin)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Index')
                ->where('summary.tracked', 4)
                ->where('summary.units', 12) // 10 + 2; the oversold −3 must not shrink it
                ->where('summary.low', 1)
                ->where('summary.out', 1)
                ->where('summary.oversold', 1)
            );

        $this->actingAs($this->admin)
            ->get(route('inventory.index', ['filter' => ['state' => 'oversold']]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('stocks.data', 1)->where('stocks.data.0.id', $oversold->id));

        $this->actingAs($this->admin)
            ->get(route('inventory.index', ['filter' => ['state' => 'low']]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('stocks.data', 1)->where('stocks.data.0.id', $low->id));
    }

    /* ------------------------------------------------------------------ */
    /* Counting in containers */
    /* ------------------------------------------------------------------ */

    /**
     * Goods arrive, and are counted, the way they are boxed. Five cases of
     * twelve and three loose is sixty-three — nobody should have to work that
     * out standing at the shelf.
     */
    public function test_a_restock_can_be_counted_in_containers_plus_loose(): void
    {
        $this->move([
            'mode' => 'restock',
            'quantity' => 5,
            'units_each' => 12,
            'unit_label' => 'កេស',
            'loose' => 3,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(73, $this->stock->fresh()->qty);

        $log = InventoryLog::where('product_id', $this->product->id)->latest('id')->firstOrFail();
        $this->assertSame(63, $log->qty_change);
        $this->assertSame('5 × 12 per កេស, plus 3 loose', $log->note);
    }

    /** A stocktake counted in cases is still one absolute figure. */
    public function test_a_count_in_containers_is_absolute(): void
    {
        $this->move([
            'mode' => 'count',
            'quantity' => 2,
            'units_each' => 12,
        ])->assertRedirect();

        // 24 counted against 10 on the books.
        $this->assertSame(24, $this->stock->fresh()->qty);
        $this->assertSame(14, InventoryLog::latest('id')->value('qty_change'));
    }

    /** Blank means singles, exactly as before. */
    public function test_a_movement_without_a_container_is_unchanged(): void
    {
        $this->move(['mode' => 'restock', 'quantity' => 4])->assertRedirect();

        $this->assertSame(14, $this->stock->fresh()->qty);
        $this->assertNull(InventoryLog::latest('id')->value('note'));
    }

    public function test_a_written_note_beats_the_generated_one(): void
    {
        $this->move([
            'mode' => 'restock',
            'quantity' => 2,
            'units_each' => 6,
            'note' => 'Invoice 4410',
        ])->assertRedirect();

        $this->assertSame('Invoice 4410', InventoryLog::latest('id')->value('note'));
    }
}
