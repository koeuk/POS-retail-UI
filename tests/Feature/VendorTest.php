<?php

namespace Tests\Feature;

use App\Enums\Action;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class VendorTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $admin;

    private Vendor $vendor;

    private User $vendorUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->vendor = Vendor::factory()->create(['name' => 'Angkor Drinks']);
        $this->vendorUser = User::factory()->create([
            'role' => Role::Vendor,
            'vendor_id' => $this->vendor->id,
            'store_id' => null,
            'is_active' => true,
        ]);
    }

    private function cashierPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Cashier',
            'email' => 'new.cashier@example.com',
            'password' => 'Str0ng!Passw0rd#2026',
            'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'role' => 'cashier',
            'store_id' => $this->store->id,
            'is_active' => true,
        ], $overrides);
    }

    /* ------------------------------------------------------------------ */
    /* The vendor role */
    /* ------------------------------------------------------------------ */

    public function test_a_vendor_runs_the_shop_but_not_the_audit_trail_or_the_vendor_list(): void
    {
        foreach ([Permission::Pos, Permission::Products, Permission::Reports, Permission::Users] as $p) {
            $this->assertTrue($this->vendorUser->hasPermission($p), $p->value);
        }

        $this->assertFalse($this->vendorUser->hasPermission(Permission::Activity));
        $this->assertFalse($this->vendorUser->hasPermission(Permission::Vendors));

        $this->actingAs($this->vendorUser)->get('/vendors')->assertForbidden();
        $this->actingAs($this->vendorUser)->get('/activity')->assertForbidden();
    }

    public function test_a_vendor_may_add_and_edit_but_not_delete_by_default(): void
    {
        $this->assertTrue($this->vendorUser->mayDo(Permission::Products, Action::Create));
        $this->assertTrue($this->vendorUser->mayDo(Permission::Products, Action::Update));
        $this->assertFalse($this->vendorUser->mayDo(Permission::Products, Action::Delete));

        $product = Product::factory()->create();

        $this->actingAs($this->vendorUser)
            ->delete(route('products.destroy', ['product' => $product->uuid]))
            ->assertForbidden();
    }

    public function test_an_admin_can_grant_one_vendor_account_delete(): void
    {
        $this->vendorUser->update(['permissions' => [
            'products' => array_fill_keys(Action::values(), true),
        ]]);

        $this->assertTrue($this->vendorUser->fresh()->mayDo(Permission::Products, Action::Delete));
    }

    public function test_a_vendor_account_must_name_its_vendor(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->cashierPayload(['role' => 'vendor', 'store_id' => null]))
            ->assertSessionHasErrors('vendor_id');

        $this->actingAs($this->admin)
            ->post(route('users.store'), $this->cashierPayload(['role' => 'vendor', 'store_id' => null, 'vendor_id' => $this->vendor->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->vendor->id, User::where('email', 'new.cashier@example.com')->value('vendor_id'));
    }

    /* ------------------------------------------------------------------ */
    /* Vendors hire cashiers */
    /* ------------------------------------------------------------------ */

    public function test_a_vendor_can_create_a_cashier_on_its_own_team(): void
    {
        $other = Vendor::factory()->create();

        // Even naming another vendor, the hire lands on the creator's team.
        $this->actingAs($this->vendorUser)
            ->post(route('users.store'), $this->cashierPayload(['vendor_id' => $other->id]))
            ->assertSessionHasNoErrors();

        $hire = User::where('email', 'new.cashier@example.com')->firstOrFail();
        $this->assertSame(Role::Cashier, $hire->role);
        $this->assertSame($this->vendor->id, $hire->vendor_id);
    }

    public function test_a_vendor_can_create_nothing_but_cashiers(): void
    {
        foreach (['manager', 'vendor', 'admin'] as $role) {
            $this->actingAs($this->vendorUser)
                ->post(route('users.store'), $this->cashierPayload(['role' => $role, 'vendor_id' => $this->vendor->id]))
                ->assertSessionHasErrors('role');
        }

        $this->assertDatabaseMissing('users', ['email' => 'new.cashier@example.com']);
    }

    public function test_a_vendor_sees_and_edits_only_its_own_cashiers(): void
    {
        $own = User::factory()->cashier($this->store)->create(['vendor_id' => $this->vendor->id]);
        $shopCashier = User::factory()->cashier($this->store)->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($this->vendorUser)
            ->get('/users')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('users.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$this->vendorUser->id, $own->id])->sort()->values()->all())
                ->where('roles', fn ($roles) => collect($roles)->pluck('value')->all() === ['cashier']));

        $edit = fn (User $u) => $this->actingAs($this->vendorUser)
            ->put(route('users.update', ['user' => $u->uuid]), [
                'name' => 'Renamed',
                'email' => $u->email,
                'role' => 'cashier',
                'store_id' => $this->store->id,
                'is_active' => true,
            ]);

        $edit($own)->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $own->fresh()->name);

        $edit($shopCashier)->assertForbidden();
        $edit($manager)->assertForbidden();
    }

    public function test_a_vendor_cannot_hand_out_permissions(): void
    {
        $own = User::factory()->cashier($this->store)->create(['vendor_id' => $this->vendor->id]);

        $this->actingAs($this->vendorUser)
            ->put(route('users.permissions', ['user' => $own->uuid]), [
                'permissions' => ['reports' => array_fill_keys(Action::values(), true)],
            ])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* The vendor screen */
    /* ------------------------------------------------------------------ */

    public function test_an_admin_manages_vendors(): void
    {
        $this->actingAs($this->admin)
            ->post(route('vendors.store'), ['name' => 'Mekong Snacks', 'phone' => '012 345 678'])
            ->assertSessionHasNoErrors();

        $vendor = Vendor::where('name', 'Mekong Snacks')->firstOrFail();
        $this->assertTrue($vendor->is_active);

        $this->actingAs($this->admin)
            ->put(route('vendors.update', ['vendor' => $vendor->uuid]), ['name' => 'Mekong Snacks Co', 'is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($vendor->fresh()->is_active);

        $product = Product::factory()->create(['vendor_id' => $vendor->id]);

        $this->actingAs($this->admin)
            ->delete(route('vendors.destroy', ['vendor' => $vendor->uuid]))
            ->assertRedirect(route('vendors.index'));

        $this->assertModelMissing($vendor);
        $this->assertNull($product->fresh()->vendor_id, 'products stay, just unassigned');
    }

    public function test_a_vendor_with_accounts_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('vendors.destroy', ['vendor' => $this->vendor->uuid]))
            ->assertSessionHasErrors('vendor');

        $this->assertModelExists($this->vendor);
    }

    public function test_the_summary_credits_packs_to_their_vendor(): void
    {
        $can = Product::factory()->create(['vendor_id' => $this->vendor->id, 'name' => 'Beer can']);
        $case = Product::factory()->create([
            'vendor_id' => null,
            'parent_product_id' => $can->id,
            'units_per_pack' => 24,
            'name' => 'Beer case',
        ]);
        $unrelated = Product::factory()->create();

        $order = Order::create([
            'client_uuid' => (string) Str::uuid(),
            'order_no' => 'NO-'.Str::random(8),
            'store_id' => $this->store->id,
            'cashier_id' => $this->admin->id,
            'subtotal' => '130.00',
            'total' => '130.00',
            'paid_amount' => '130.00',
            'status' => OrderStatus::Completed,
            'synced_at' => now(),
        ]);

        foreach ([[$can, 2, '10.00'], [$case, 1, '100.00'], [$unrelated, 1, '20.00']] as [$p, $qty, $subtotal]) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $p->id,
                'product_name' => $p->name,
                'unit_price' => $subtotal,
                'qty' => $qty,
                'subtotal' => $subtotal,
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('vendors.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Vendors/Index')
                ->where('vendors.data.0.sales.revenue', '110.00')
                ->where('vendors.data.0.sales.orders', 1)
                ->where('summary.revenue', '110.00'));

        $this->actingAs($this->admin)
            ->get(route('vendors.show', ['vendor' => $this->vendor->uuid]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Vendors/Show')
                ->has('products', 1)
                // 2 cans + one case of 24, in base units.
                ->where('products.0.sold', 26)
                ->where('totals.revenue', '110.00'));
    }
}
