<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * What the backend does when the database fails mid-request.
 *
 * Every write action catches QueryException and sends the user back with a
 * sentence; the two aggregate pages (dashboard, reports) stay up with empty
 * figures. Nothing else is caught — a bug must still surface as a bug.
 */
class WriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Store::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private static function lostConnection(): QueryException
    {
        return new QueryException('mysql', 'insert into ...', [], new \RuntimeException('Lost connection'));
    }

    public function test_a_failed_save_sends_the_user_back_with_their_input_and_a_reason(): void
    {
        // The insert itself fails — the model event runs inside Eloquent's save path.
        Category::creating(fn () => throw self::lostConnection());

        $this->actingAs($this->admin)
            ->from(route('categories.index'))
            ->post(route('categories.store'), ['name' => 'Snacks'])
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('error', 'The category could not be saved. Nothing was changed — try again.')
            ->assertSessionHasInput('name', 'Snacks');

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_a_failed_pos_customer_create_answers_in_json_the_checkout_can_show(): void
    {
        Customer::creating(fn () => throw self::lostConnection());

        $this->actingAs($this->admin)
            ->postJson(route('pos.data.customers.store'), ['name' => 'Dara', 'phone' => null])
            ->assertStatus(503)
            ->assertJsonPath('message', 'The customer could not be saved — try again.');

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_the_dashboard_opens_with_empty_figures_when_the_figures_cannot_be_read(): void
    {
        DB::partialMock()->shouldReceive('table')->with('orders')->andThrow(self::lostConnection());

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('today.sales', '0.00')
                ->where('today.orders', 0)
                ->where('trend', [])
                ->where('recentOrders', [])
                ->where('flash.error', "Today's figures could not be loaded. Try again in a moment.")
            );
    }

    public function test_only_database_failures_are_caught(): void
    {
        // A programming error inside a write must not be dressed up as a database hiccup.
        Category::creating(fn () => throw new \LogicException('a bug'));

        $this->withoutExceptionHandling();
        $this->expectException(\LogicException::class);

        $this->actingAs($this->admin)->post(route('categories.store'), ['name' => 'Snacks']);
    }
}
