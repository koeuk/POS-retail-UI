<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\PerPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PerPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        Product::factory()->count(60)->create();
    }

    public function test_the_page_size_can_be_chosen_from_the_offered_options(): void
    {
        foreach ([10, 50] as $size) {
            $this->actingAs($this->admin)
                ->get(route('products.index', ['per_page' => $size]))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('products.per_page', $size)
                    ->has('products.data', $size)
                );
        }
    }

    /**
     * The value is whitelisted, not clamped. A hand-edited URL asking for a
     * million rows must fall back to the default instead of hydrating the
     * whole table.
     */
    public function test_a_size_that_is_not_offered_falls_back_to_the_default(): void
    {
        foreach ([999999, 0, -5, 7, 'abc'] as $bad) {
            $this->actingAs($this->admin)
                ->get(route('products.index', ['per_page' => $bad]))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('products.per_page', PerPage::DEFAULT)
                );
        }
    }

    public function test_every_paginated_screen_honours_per_page(): void
    {
        foreach (['products.index', 'customers.index', 'users.index', 'orders.index', 'inventory.index'] as $route) {
            $this->actingAs($this->admin)
                ->get(route($route, ['per_page' => 10]))
                ->assertOk();
        }
    }

    public function test_the_options_match_the_dropdown(): void
    {
        $this->assertSame([10, 20, 50, 100, 150, 200], PerPage::OPTIONS);
        $this->assertContains(PerPage::DEFAULT, PerPage::OPTIONS);
    }

    public function test_the_whitelist_is_shared_with_the_client_so_the_two_lists_cannot_drift(): void
    {
        // Pagination.vue reads these shared props instead of keeping its own
        // copy; this pins the contract so removing the share breaks loudly.
        $this->actingAs($this->admin)
            ->get(route('products.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pagination.options', PerPage::OPTIONS)
                ->where('pagination.default', PerPage::DEFAULT)
            );
    }
}
