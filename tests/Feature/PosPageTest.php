<?php

namespace Tests\Feature;

use App\Models\Register;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PosPageTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        Register::factory()->create(['store_id' => $this->store->id]);
    }

    public function test_a_cashier_can_open_the_pos_screen(): void
    {
        $cashier = User::factory()->cashier($this->store)->create();

        $this->actingAs($cashier)
            ->get(route('pos'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Index')
                ->where('boot.store_id', $this->store->id)
                ->where('boot.cashier_id', $cashier->id)
                ->where('boot.store_name', $this->store->name)
            );
    }

    /** Managers and admins run the till too, so /pos is not cashier-only. */
    public function test_managers_and_admins_can_open_the_pos_screen(): void
    {
        $this->actingAs(User::factory()->manager($this->store)->create())
            ->get(route('pos'))->assertOk();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('pos'))->assertOk();
    }

    public function test_guests_cannot_open_the_pos_screen(): void
    {
        $this->get(route('pos'))->assertRedirect(route('login'));
    }

    /**
     * An admin has no store binding, so the POS falls back to the first store
     * rather than failing — the till must always be able to open.
     */
    public function test_an_admin_without_a_store_still_gets_one(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('pos'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('boot.store_id', $this->store->id)
            );
    }
}
