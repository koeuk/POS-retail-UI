<?php

namespace Tests\Feature;

use App\Enums\Action;
use App\Enums\Permission;
use App\Models\Activity;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $admin;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->cashier = User::factory()->create([
            'role' => 'cashier',
            'is_active' => true,
            'store_id' => $this->store->id,
        ]);
    }

    private function url(User $user): string
    {
        return route('users.permissions', ['user' => $user->uuid]);
    }

    /** @return array<string, bool> */
    private function allActions(bool $granted): array
    {
        return array_fill_keys(Action::values(), $granted);
    }

    public function test_an_admin_can_grant_a_permission_on_its_own(): void
    {
        $this->actingAs($this->admin)
            ->put($this->url($this->cashier), [
                'permissions' => [Permission::Reports->value => $this->allActions(true)],
            ])
            ->assertRedirect();

        $this->assertTrue($this->cashier->fresh()->hasPermission(Permission::Reports));
    }

    /** The whole point of the separate endpoint: nothing else may change. */
    public function test_saving_permissions_leaves_the_rest_of_the_account_alone(): void
    {
        $before = $this->cashier->only(['name', 'email', 'role', 'store_id', 'is_active']);

        $this->actingAs($this->admin)->put($this->url($this->cashier), [
            'permissions' => [Permission::Reports->value => $this->allActions(true)],
            // Smuggled in alongside the switches — must be ignored.
            'name' => 'Renamed By Permissions Dialog',
            'role' => 'admin',
            'is_active' => false,
        ]);

        $after = $this->cashier->fresh();

        $this->assertSame($before['name'], $after->name);
        $this->assertSame($before['role'], $after->role);
        $this->assertSame($before['is_active'], $after->is_active);
    }

    /** A key that is not a real Permission case must never reach the column. */
    public function test_unknown_permission_keys_are_dropped(): void
    {
        $this->actingAs($this->admin)->put($this->url($this->cashier), [
            'permissions' => [
                Permission::Reports->value => $this->allActions(true),
                'not_a_real_permission' => $this->allActions(true),
            ],
        ]);

        $this->assertArrayNotHasKey('not_a_real_permission', $this->cashier->fresh()->permissions);
    }

    public function test_a_non_admin_cannot_hand_out_permissions(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);

        // Managers do not hold `users` at all, so the middleware stops them.
        $this->actingAs($manager)
            ->put($this->url($this->cashier), ['permissions' => [Permission::Reports->value => $this->allActions(true)]])
            ->assertForbidden();

        // Even granted the Staff screen, only an admin may edit permissions.
        $manager->update(['permissions' => [Permission::Users->value => $this->allActions(true)]]);

        $this->actingAs($manager)
            ->put($this->url($this->cashier), ['permissions' => [Permission::Reports->value => $this->allActions(true)]])
            ->assertForbidden();

        $this->assertFalse($this->cashier->fresh()->hasPermission(Permission::Reports));
    }

    public function test_an_admin_row_cannot_be_given_overrides(): void
    {
        $other = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->put($this->url($other), ['permissions' => [Permission::Reports->value => $this->allActions(false)]])
            ->assertSessionHasErrors('permissions');

        $this->assertNull($other->fresh()->permissions);
    }

    /** The point of the matrix: reach an area, but not every action in it. */
    public function test_actions_can_be_granted_individually(): void
    {
        $this->actingAs($this->admin)->put($this->url($this->cashier), [
            'permissions' => [
                Permission::Products->value => [
                    'view' => true,
                    'create' => true,
                    'update' => true,
                    'delete' => false,
                ],
            ],
        ]);

        $cashier = $this->cashier->fresh();

        $this->assertTrue($cashier->hasPermission(Permission::Products));
        $this->assertTrue($cashier->mayDo(Permission::Products, Action::Update));
        $this->assertFalse($cashier->mayDo(Permission::Products, Action::Delete));
    }

    /** Every action off means the area itself is closed. */
    public function test_an_area_with_no_actions_is_no_access(): void
    {
        $this->actingAs($this->admin)->put($this->url($this->cashier), [
            'permissions' => [Permission::Products->value => $this->allActions(false)],
        ]);

        $this->assertFalse($this->cashier->fresh()->hasPermission(Permission::Products));
    }

    /**
     * The wall, not the label: a user who may edit but not delete must be
     * refused by the server, whatever the UI chose to render.
     */
    public function test_a_denied_action_is_refused_by_the_server(): void
    {
        $manager = User::factory()->create([
            'role' => 'manager',
            'is_active' => true,
            'permissions' => [
                Permission::Products->value => [
                    'view' => true, 'create' => true, 'update' => true, 'delete' => false,
                ],
            ],
        ]);

        $product = Product::factory()->create(['name' => 'Keep Me']);

        $this->actingAs($manager)
            ->put(route('products.update', ['product' => $product->uuid]), [
                'category_id' => $product->category_id,
                'name' => 'Renamed',
                'sku' => $product->sku,
                'sell_price' => $product->sell_price,
                'unit' => $product->unit,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $product->fresh()->name);

        $this->actingAs($manager)
            ->delete(route('products.destroy', ['product' => $product->uuid]))
            ->assertForbidden();

        $this->assertModelExists($product);
    }

    /**
     * The whole chain for one account: nav flag, policy, controller gate,
     * untouched areas, and the matrix the frontend renders from.
     */
    public function test_the_matrix_resolves_consistently_everywhere(): void
    {
        $manager = User::factory()->create([
            'role' => 'manager',
            'is_active' => true,
            'permissions' => [
                'products' => ['view' => true, 'create' => true, 'update' => true, 'delete' => false],
                'inventory' => ['view' => true, 'create' => false, 'update' => false, 'delete' => false],
            ],
        ]);

        $product = Product::factory()->create();

        // The area stays reachable, so the nav item stays visible.
        $this->assertTrue($manager->hasPermission(Permission::Products));
        $this->assertTrue($manager->permissionFlags()['products']);

        // Policies honour the matrix.
        $this->assertTrue($manager->can('update', $product));
        $this->assertFalse($manager->can('delete', $product));

        // Inventory has no policy — its gate lives in the controller.
        $this->assertTrue($manager->mayDo(Permission::Inventory, Action::View));
        $this->assertFalse($manager->mayDo(Permission::Inventory, Action::Update));
        $this->actingAs($manager)->post(route('inventory.store'), [])->assertForbidden();

        // An area with no override still follows the role default.
        $this->assertTrue($manager->mayDo(Permission::Customers, Action::Delete));

        // What the frontend hides buttons with.
        $this->assertSame(
            ['view' => true, 'create' => true, 'update' => true, 'delete' => false],
            $manager->actionMatrix()['products'],
        );
    }

    /** Turning a switch off is recorded in the access log like any other. */
    public function test_the_change_is_written_to_the_activity_log(): void
    {
        $this->actingAs($this->admin)->put($this->url($this->cashier), [
            'permissions' => [Permission::Reports->value => $this->allActions(true)],
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => Activity::LOG_ACCESS,
            'subject_type' => User::class,
            'subject_id' => $this->cashier->id,
            'causer_id' => $this->admin->id,
        ]);
    }
}
