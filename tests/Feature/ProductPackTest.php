<?php

namespace Tests\Feature;

use App\Enums\InventoryLogType;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Register;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Selling one thing several ways.
 *
 * A case of beer is 24 cans on the same shelf, so the whole feature rests on
 * one invariant: however it was sold, the stock that moves is the base
 * product's, in base units.
 */
class ProductPackTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Register $register;

    private User $cashier;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->register = Register::factory()->create(['store_id' => $this->store->id]);
        $this->cashier = User::factory()->cashier($this->store)->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function can(int $qty = 120): Product
    {
        $product = Product::factory()->create(['name' => 'Beer 330ml', 'unit' => 'can', 'sell_price' => '0.75']);

        Stock::create(['product_id' => $product->id, 'store_id' => $this->store->id, 'qty' => $qty]);

        return $product;
    }

    private function packOf(Product $base, int $units, string $name, string $price): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'parent_product_id' => $base->id,
            'units_per_pack' => $units,
            'sell_price' => $price,
            'unit' => 'pack',
        ]);
    }

    private function sell(Product $product, int $qty): void
    {
        $this->actingAs($this->cashier)->postJson(route('pos.data.orders.sync'), [
            'orders' => [[
                'client_uuid' => (string) Str::uuid(),
                'register_id' => $this->register->id,
                'customer_id' => null,
                'created_offline_at' => now()->toIso8601String(),
                'discount_amount' => '0.00',
                'items' => [[
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'qty' => $qty,
                    'unit_price' => $product->sell_price,
                    'discount' => '0.00',
                ]],
                'payments' => [['method' => 'cash', 'amount' => '1000.00', 'reference_no' => null]],
            ]],
        ])->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* The invariant */
    /* ------------------------------------------------------------------ */

    public function test_selling_a_case_takes_its_units_off_the_base_products_shelf(): void
    {
        $can = $this->can(120);
        $case = $this->packOf($can, 24, 'Beer — case of 24', '16.00');

        $this->sell($case, 1);

        $this->assertSame(96, Stock::where('product_id', $can->id)->value('qty'));

        // The pack itself never grows a shelf of its own.
        $this->assertDatabaseMissing('stocks', ['product_id' => $case->id]);
    }

    public function test_every_pack_size_draws_down_the_same_shelf(): void
    {
        $can = $this->can(120);
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');
        $half = $this->packOf($can, 12, 'Half case', '8.40');
        $six = $this->packOf($can, 6, 'Six-pack', '4.32');

        $this->sell($case, 1);   // -24
        $this->sell($half, 1);   // -12
        $this->sell($six, 2);    // -12
        $this->sell($can, 3);    //  -3

        $this->assertSame(69, Stock::where('product_id', $can->id)->value('qty'));
    }

    /** The ledger has to name the shelf that moved, not the row that was rung up. */
    public function test_the_movement_is_logged_against_the_base_product_in_base_units(): void
    {
        $can = $this->can(120);
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->sell($case, 2);

        $log = InventoryLog::where('type', InventoryLogType::Sale)->latest('id')->first();

        $this->assertSame($can->id, $log->product_id);
        $this->assertSame(-48, $log->qty_change);
    }

    /* ------------------------------------------------------------------ */
    /* Creating them */
    /* ------------------------------------------------------------------ */

    public function test_creating_a_pack_does_not_seed_stock_rows(): void
    {
        $can = $this->can(50);

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $can->category_id,
            'parent_product_id' => $can->id,
            'units_per_pack' => 24,
            'name' => 'Beer — case of 24',
            'sku' => 'BEER-CASE',
            'sell_price' => '16.00',
            'unit' => 'case',
            'track_stock' => true,
            'is_active' => true,
            'opening_qty' => 99,
        ])->assertRedirect();

        $case = Product::where('sku', 'BEER-CASE')->firstOrFail();

        $this->assertSame($can->id, $case->parent_product_id);
        $this->assertSame(24, $case->units_per_pack);
        $this->assertDatabaseMissing('stocks', ['product_id' => $case->id]);
    }

    /**
     * The whole point of the inline list: one form, one submit, and the shop
     * ends up with a can that holds the stock and three prices beside it.
     */
    public function test_a_product_and_its_pack_sizes_are_created_in_one_submit(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $category->id,
            'name' => 'Angkor Beer 330ml',
            'sku' => 'BEER-CAN',
            'sell_price' => '0.75',
            'unit' => 'can',
            'track_stock' => true,
            'is_active' => true,
            'opening_qty' => 264,
            'packs' => [
                ['name' => 'Half case', 'units_per_pack' => 12, 'sell_price' => '8.40'],
                ['name' => 'Six-pack', 'units_per_pack' => 6, 'sell_price' => '4.32'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $can = Product::where('sku', 'BEER-CAN')->firstOrFail();

        $this->assertSame(2, $can->packs()->count());
        $this->assertSame(264, (int) Stock::where('product_id', $can->id)->sum('qty'));

        // Derived, so nobody has to invent a code per size.
        $this->assertSame(['BEER-CAN-12', 'BEER-CAN-6'], $can->packs()->orderBy('sku')->pluck('sku')->sort()->values()->all());

        // And the stock still lives in one place.
        $this->assertSame(0, Stock::whereIn('product_id', $can->packs()->pluck('id'))->count());
    }

    public function test_editing_adds_updates_and_removes_pack_sizes(): void
    {
        $can = $this->can(264);
        $six = $this->packOf($can, 6, 'Six-pack', '4.32');
        $half = $this->packOf($can, 12, 'Half case', '8.40');

        $this->actingAs($this->admin)->put(route('products.update', $can), [
            'category_id' => $can->category_id,
            'name' => $can->name,
            'sku' => $can->sku,
            'sell_price' => $can->sell_price,
            'unit' => $can->unit,
            'track_stock' => true,
            'is_active' => true,
            'packs' => [
                // Kept, with a new price.
                ['id' => $six->id, 'name' => 'Six-pack', 'units_per_pack' => 6, 'sell_price' => '4.50'],
                // Added.
                ['name' => 'Full case', 'units_per_pack' => 24, 'sell_price' => '16.00'],
                // $half is absent, so it goes.
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('4.50', $six->fresh()->sell_price);
        $this->assertDatabaseMissing('products', ['id' => $half->id]);
        $this->assertSame(2, $can->packs()->count());
    }

    /** Removing a pack that has already been sold must not orphan the receipt. */
    public function test_a_pack_with_sales_is_deactivated_rather_than_deleted(): void
    {
        $can = $this->can(264);
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->sell($case, 1);

        $this->actingAs($this->admin)->put(route('products.update', $can), [
            'category_id' => $can->category_id,
            'name' => $can->name,
            'sku' => $can->sku,
            'sell_price' => $can->sell_price,
            'unit' => $can->unit,
            'track_stock' => true,
            'is_active' => true,
            'packs' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('products', ['id' => $case->id, 'is_active' => false]);
    }

    /**
     * A pack of one is legitimate: the same item sold under another name or at
     * another price — a single pulled from a case, say. Only zero is refused.
     */
    public function test_a_pack_row_may_hold_a_single_unit(): void
    {
        $can = $this->can();

        $this->actingAs($this->admin)->put(route('products.update', $can), [
            'category_id' => $can->category_id,
            'name' => $can->name,
            'sku' => $can->sku,
            'sell_price' => $can->sell_price,
            'unit' => $can->unit,
            'track_stock' => true,
            'is_active' => true,
            'packs' => [['name' => 'Half case', 'units_per_pack' => 1, 'sell_price' => '2.50']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $can->packs()->value('units_per_pack'));
    }

    public function test_a_pack_row_cannot_hold_nothing(): void
    {
        $can = $this->can();

        $this->actingAs($this->admin)->put(route('products.update', $can), [
            'category_id' => $can->category_id,
            'name' => $can->name,
            'sku' => $can->sku,
            'sell_price' => $can->sell_price,
            'unit' => $can->unit,
            'track_stock' => true,
            'is_active' => true,
            'packs' => [['name' => 'Nothing', 'units_per_pack' => 0, 'sell_price' => '2.50']],
        ])->assertSessionHasErrors('packs.0.units_per_pack');
    }

    /** One level only: the base-unit maths stops making sense beyond it. */
    public function test_a_pack_cannot_belong_to_another_pack(): void
    {
        $can = $this->can();
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $can->category_id,
            'parent_product_id' => $case->id,
            'units_per_pack' => 2,
            'name' => 'Two cases',
            'sku' => 'BEER-2CASE',
            'sell_price' => '31.00',
            'unit' => 'pallet',
            'track_stock' => true,
            'is_active' => true,
        ])->assertSessionHasErrors('parent_product_id');
    }

    public function test_a_pack_must_say_how_many_units_it_holds(): void
    {
        $can = $this->can();

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $can->category_id,
            'parent_product_id' => $can->id,
            'name' => 'Beer — case',
            'sku' => 'BEER-CASE-2',
            'sell_price' => '16.00',
            'unit' => 'case',
            'track_stock' => true,
            'is_active' => true,
        ])->assertSessionHasErrors('units_per_pack');
    }

    public function test_a_base_product_with_packs_cannot_be_deleted(): void
    {
        $can = $this->can();
        $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->actingAs($this->admin)
            ->delete(route('products.destroy', $can))
            ->assertSessionHasErrors('product');

        $this->assertDatabaseHas('products', ['id' => $can->id]);
    }

    public function test_a_store_created_later_gets_no_stock_rows_for_packs(): void
    {
        $can = $this->can();
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->actingAs($this->admin)
            ->post(route('stores.store'), ['name' => 'Second Store'])
            ->assertRedirect();

        $second = Store::where('name', 'Second Store')->firstOrFail();

        $this->assertDatabaseHas('stocks', ['product_id' => $can->id, 'store_id' => $second->id]);
        $this->assertDatabaseMissing('stocks', ['product_id' => $case->id]);
    }

    /* ------------------------------------------------------------------ */
    /* How they are shown */
    /* ------------------------------------------------------------------ */

    /** The till needs pack stock in packs — 96 cans is 4 cases, not 96. */
    public function test_the_pos_feed_reports_pack_stock_in_whole_packs(): void
    {
        $can = $this->can(99);
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $feed = $this->actingAs($this->cashier)
            ->getJson(route('pos.data.products'))
            ->assertOk()
            ->json('products');

        $rows = collect($feed)->keyBy('id');

        $this->assertSame(99, $rows[$can->id]['stock_qty']);
        $this->assertSame(4, $rows[$case->id]['stock_qty']);
        $this->assertSame(24, $rows[$case->id]['units_per_pack']);
    }

    public function test_the_menu_lists_packs_under_their_product_rather_than_separately(): void
    {
        $can = $this->can();
        $this->packOf($can, 24, 'Case of 24', '16.00');

        $products = $this->get(route('menu'))
            ->assertOk()
            ->viewData('page')['props']['products'];

        $this->assertCount(1, $products);
        $this->assertSame('Beer 330ml', $products[0]['name']);
        $this->assertCount(1, $products[0]['packs']);
        $this->assertSame('Case of 24', $products[0]['packs'][0]['name']);
    }

    /**
     * A pack is a way of buying a product, not a product. Listing it put rows
     * reading "0 pcs" beside the real item and doubled the catalogue.
     */
    public function test_the_catalogue_lists_products_not_their_packs(): void
    {
        $can = $this->can(120);
        $this->packOf($can, 6, 'Six-pack', '4.32');
        $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->actingAs($this->admin)
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.id', $can->id)
                ->where('products.data.0.packs_count', 2)
                // Carried so the row can show a range rather than one price.
                ->where('products.data.0.pack_max_price', '16.00')
            );
    }

    /** Scanning a case's barcode should find the product it belongs to. */
    public function test_searching_finds_a_product_by_its_packs_details(): void
    {
        $can = $this->can();
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->actingAs($this->admin)
            ->get(route('products.index', ['filter' => ['search' => $case->sku]]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.id', $can->id)
            );
    }

    public function test_the_detail_page_lists_every_way_to_buy_it(): void
    {
        $can = $this->can();
        $this->packOf($can, 6, 'Six-pack', '4.32');

        $this->actingAs($this->admin)
            ->get(route('products.show', $can))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('packs', 1)
                ->where('packs.0.name', 'Six-pack')
                ->where('packs.0.units_per_pack', 6)
            );
    }

    /* ------------------------------------------------------------------ */
    /* Receiving stock from the product screen */
    /* ------------------------------------------------------------------ */

    private function editPayload(Product $product, array $extra = []): array
    {
        return array_merge([
            'category_id' => $product->category_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'sell_price' => $product->sell_price,
            'unit' => $product->unit,
            'track_stock' => true,
            'is_active' => true,
        ], $extra);
    }

    /**
     * The shortcut must go through the ledger, not around it: a quantity that
     * changed because "someone edited the product" is a figure nobody can
     * explain a week later.
     */
    public function test_receiving_stock_from_the_product_screen_records_a_restock(): void
    {
        $can = $this->can(120);

        $this->actingAs($this->admin)
            ->put(route('products.update', $can), $this->editPayload($can, [
                'add_stock' => 24,
                'add_stock_note' => 'Invoice 8812',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(144, Stock::where('product_id', $can->id)->value('qty'));

        $log = InventoryLog::where('product_id', $can->id)->latest('id')->firstOrFail();

        $this->assertSame(InventoryLogType::Restock, $log->type);
        $this->assertSame(24, $log->qty_change);
        $this->assertSame('Invoice 8812', $log->note);
        $this->assertSame($this->admin->id, $log->created_by);
    }

    /** An ordinary edit with the field left blank must not touch stock. */
    public function test_saving_a_product_without_the_field_leaves_stock_alone(): void
    {
        $can = $this->can(120);

        $this->actingAs($this->admin)
            ->put(route('products.update', $can), $this->editPayload($can, ['name' => 'Renamed']))
            ->assertRedirect();

        $this->assertSame(120, Stock::where('product_id', $can->id)->value('qty'));
        $this->assertSame(0, InventoryLog::where('product_id', $can->id)->count());
    }

    /** Receiving two cases of 24 puts 48 cans on the shelf, not 2. */
    public function test_receiving_a_pack_lands_on_the_parent_in_base_units(): void
    {
        $can = $this->can(100);
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->actingAs($this->admin)
            ->put(route('products.update', $case), $this->editPayload($case, ['add_stock' => 2]))
            ->assertRedirect();

        $this->assertSame(148, Stock::where('product_id', $can->id)->value('qty'));
        $this->assertDatabaseMissing('stocks', ['product_id' => $case->id]);
    }

    /**
     * Editing a pack must not quietly demote it. The form no longer sends the
     * pack keys at all, and merging defaults for them turned a case of 24 into
     * a standalone product with a shelf of its own.
     */
    public function test_editing_a_pack_keeps_it_a_pack(): void
    {
        $can = $this->can();
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->actingAs($this->admin)
            ->put(route('products.update', $case), $this->editPayload($case, ['sell_price' => '17.50']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $case->refresh();

        $this->assertSame($can->id, $case->parent_product_id);
        $this->assertSame(24, $case->units_per_pack);
        $this->assertSame('17.50', $case->sell_price);
    }

    /**
     * A delivery arrives the way it is packed. Three cases and a hundred loose
     * packets is 172 packets, and nobody should have to work that out at 7am.
     */
    public function test_a_delivery_can_be_counted_in_cases_plus_loose_units(): void
    {
        $noodle = $this->can(0);
        $case = $this->packOf($noodle, 24, 'Case of 24', '16.00');

        $this->actingAs($this->admin)
            ->put(route('products.update', $noodle), $this->editPayload($noodle, [
                'add_stock' => 3,
                'add_stock_pack_id' => $case->id,
                'add_stock_loose' => 100,
                'add_stock_note' => 'Monday delivery',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // 3 × 24 + 100.
        $this->assertSame(172, Stock::where('product_id', $noodle->id)->value('qty'));
        $this->assertSame(172, InventoryLog::where('product_id', $noodle->id)->latest('id')->value('qty_change'));
    }

    /** Loose units alone still work, with no pack chosen. */
    public function test_a_delivery_of_loose_units_only(): void
    {
        $noodle = $this->can(10);

        $this->actingAs($this->admin)
            ->put(route('products.update', $noodle), $this->editPayload($noodle, ['add_stock' => 5]))
            ->assertRedirect();

        $this->assertSame(15, Stock::where('product_id', $noodle->id)->value('qty'));
    }

    /**
     * The pack id decides the multiplier, so a hand-edited form must not be
     * able to point it at somebody else's case size.
     */
    public function test_a_pack_belonging_to_another_product_is_not_honoured(): void
    {
        $noodle = $this->can(0);
        $other = Product::factory()->create(['name' => 'Something else']);
        $foreignPack = $this->packOf($other, 500, 'Pallet', '900.00');

        $this->actingAs($this->admin)
            ->put(route('products.update', $noodle), $this->editPayload($noodle, [
                'add_stock' => 2,
                'add_stock_pack_id' => $foreignPack->id,
            ]))
            ->assertRedirect();

        // Counted as singles, not 2 × 500.
        $this->assertSame(2, Stock::where('product_id', $noodle->id)->value('qty'));
    }

    /**
     * A shop that buys beer by the 24 but only sells singles should not have
     * to invent a priced "case" on the POS grid just to book a delivery.
     */
    public function test_a_container_size_can_be_given_for_one_delivery_without_creating_a_pack(): void
    {
        $can = $this->can(0);

        $this->actingAs($this->admin)
            ->put(route('products.update', $can), $this->editPayload($can, [
                'add_stock' => 10,
                'add_stock_units_each' => 24,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(240, Stock::where('product_id', $can->id)->value('qty'));

        // And nothing new turned up on the grid.
        $this->assertSame(0, $can->packs()->count());
    }

    public function test_a_one_off_container_size_combines_with_loose_units(): void
    {
        $can = $this->can(0);

        $this->actingAs($this->admin)
            ->put(route('products.update', $can), $this->editPayload($can, [
                'add_stock' => 3,
                'add_stock_units_each' => 24,
                'add_stock_loose' => 100,
            ]))
            ->assertRedirect();

        $this->assertSame(172, Stock::where('product_id', $can->id)->value('qty'));
    }

    /** A real pack outranks a typed size — the saved figure is the true one. */
    public function test_a_saved_pack_wins_over_a_typed_container_size(): void
    {
        $can = $this->can(0);
        $case = $this->packOf($can, 24, 'Case of 24', '16.00');

        $this->actingAs($this->admin)
            ->put(route('products.update', $can), $this->editPayload($can, [
                'add_stock' => 2,
                'add_stock_pack_id' => $case->id,
                'add_stock_units_each' => 999,
            ]))
            ->assertRedirect();

        $this->assertSame(48, Stock::where('product_id', $can->id)->value('qty'));
    }

    /**
     * Container sizes vary by product — water is twelve bottles to a case,
     * canned fish something else — so the pair is free-form and optional, and
     * the movement records what was actually said.
     */
    public function test_the_container_pair_is_written_into_the_movement_note(): void
    {
        $water = Product::factory()->create(['name' => 'ទឹកសុទ្ធ 500ml', 'unit' => 'ដប']);
        Stock::create(['product_id' => $water->id, 'store_id' => $this->store->id, 'qty' => 0]);

        $this->actingAs($this->admin)
            ->put(route('products.update', $water), $this->editPayload($water, [
                'add_stock' => 5,
                'add_stock_units_each' => 12,
                'add_stock_unit_label' => 'កេស',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(60, Stock::where('product_id', $water->id)->value('qty'));

        $log = InventoryLog::where('product_id', $water->id)->latest('id')->firstOrFail();
        $this->assertSame('Received 5 × 12 per កេស', $log->note);
    }

    /** A note the receiver actually wrote always wins over the generated one. */
    public function test_a_written_note_is_kept(): void
    {
        $can = $this->can(0);

        $this->actingAs($this->admin)
            ->put(route('products.update', $can), $this->editPayload($can, [
                'add_stock' => 2,
                'add_stock_units_each' => 24,
                'add_stock_note' => 'Invoice 5567',
            ]))
            ->assertRedirect();

        $this->assertSame('Invoice 5567', InventoryLog::where('product_id', $can->id)->latest('id')->value('note'));
    }

    /** No container given means singles, however many arrive. */
    public function test_a_blank_container_pair_counts_singles(): void
    {
        $can = $this->can(0);

        $this->actingAs($this->admin)
            ->put(route('products.update', $can), $this->editPayload($can, ['add_stock' => 7]))
            ->assertRedirect();

        $this->assertSame(7, Stock::where('product_id', $can->id)->value('qty'));
    }

    /**
     * The create form has no Add stock section, so it must not post the fields
     * that belong to it. It once sent the Select's "single" sentinel as a pack
     * id, which failed integer validation on a field the page never shows —
     * the form just refused to save, with the error nowhere on screen.
     */
    public function test_creating_a_product_is_not_blocked_by_receipt_fields(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $category->id,
            'name' => 'Plain product',
            'sku' => 'PLAIN-1',
            'sell_price' => '1.50',
            'unit' => 'pcs',
            'track_stock' => true,
            'is_active' => true,
            'opening_qty' => 5,
            'low_stock_threshold' => 2,
        ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'PLAIN-1')->firstOrFail();

        $this->assertSame(5, (int) Stock::where('product_id', $product->id)->sum('qty'));
    }

    /** And a product created with pack rows still works. */
    public function test_creating_a_product_with_packs_still_works(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin)->post(route('products.store'), [
            'category_id' => $category->id,
            'name' => 'Boxed product',
            'sku' => 'BOXED-1',
            'sell_price' => '1.00',
            'unit' => 'pcs',
            'track_stock' => true,
            'is_active' => true,
            'opening_qty' => 0,
            'packs' => [['name' => 'Case', 'units_per_pack' => 24, 'sell_price' => '20.00']],
        ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Product::where('sku', 'BOXED-1')->firstOrFail()->packs()->count());
    }

    public function test_a_case_size_is_saved_for_counting_and_must_hold_at_least_two(): void
    {
        $product = $this->can();
        $payload = fn (mixed $caseSize) => [
            'category_id' => $product->category_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'sell_price' => '0.75',
            'unit' => 'can',
            'track_stock' => true,
            'is_active' => true,
            'case_size' => $caseSize,
        ];

        $this->actingAs($this->admin)->put(route('products.update', $product), $payload(24))->assertSessionHasNoErrors();
        $this->assertSame(24, $product->fresh()->case_size);

        // A case of one is not a case — there would be nothing to count.
        $this->actingAs($this->admin)->from(route('products.edit', $product))
            ->put(route('products.update', $product), $payload(1))
            ->assertSessionHasErrors('case_size');
        $this->assertSame(24, $product->fresh()->case_size, 'a rejected value leaves the old one alone');

        // Clearing the field means "count singles again", not a case of zero.
        $this->actingAs($this->admin)->put(route('products.update', $product), $payload(''))->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->case_size);
    }
}
