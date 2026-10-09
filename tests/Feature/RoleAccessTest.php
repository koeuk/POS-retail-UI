<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::create([
            'name' => 'Test Store',
            'address' => '1 Test Way',
            'phone' => '000',
        ]);
    }

    private function makeUser(Role $role, bool $active = true): User
    {
        return User::create([
            'name' => ucfirst($role->value),
            'email' => $role->value.'@test.local',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'role' => $role,
            'store_id' => $role === Role::Admin ? null : $this->store->id,
            'is_active' => $active,
        ]);
    }

    public function test_all_three_roles_can_log_in(): void
    {
        foreach ([Role::Admin, Role::Manager, Role::Cashier] as $role) {
            $user = $this->makeUser($role);

            $response = $this->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

            $this->assertAuthenticatedAs($user);
            $response->assertRedirect();

            $this->post('/logout');
        }
    }

    public function test_admin_and_manager_reach_the_admin_area(): void
    {
        foreach ([Role::Admin, Role::Manager] as $role) {
            $this->actingAs($this->makeUser($role))
                ->getJson('/admin/ping')
                ->assertOk()
                ->assertJson(['ok' => true, 'area' => 'admin']);
        }
    }

    public function test_cashier_is_forbidden_from_the_admin_area(): void
    {
        $this->actingAs($this->makeUser(Role::Cashier))
            ->getJson('/admin/ping')
            ->assertForbidden();
    }

    public function test_heartbeat_returns_store_context_for_a_cashier(): void
    {
        $cashier = $this->makeUser(Role::Cashier);

        $this->actingAs($cashier)
            ->getJson('/pos/data/heartbeat')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'user_id' => $cashier->id,
                'store_id' => $this->store->id,
            ])
            ->assertJsonStructure(['ok', 'server_time', 'user_id', 'store_id', 'csrf_token']);
    }

    public function test_heartbeat_is_unauthorised_for_a_guest(): void
    {
        $this->getJson('/pos/data/heartbeat')->assertUnauthorized();
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $user = $this->makeUser(Role::Cashier, active: false);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Deactivation must bite mid-session, not merely at the next login —
     * otherwise a dismissed cashier keeps selling until the session expires,
     * which is now 12 hours.
     */
    public function test_deactivation_locks_out_an_already_authenticated_user(): void
    {
        $cashier = $this->makeUser(Role::Cashier);

        $this->actingAs($cashier)->getJson('/pos/data/heartbeat')->assertOk();

        $cashier->update(['is_active' => false]);

        $this->actingAs($cashier)->getJson('/pos/data/heartbeat')->assertForbidden();
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@test.local',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
    }
}
