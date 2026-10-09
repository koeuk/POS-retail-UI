<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_creating_a_record_is_logged_with_its_causer(): void
    {
        $this->actingAs($this->admin);

        $customer = Customer::factory()->create(['name' => 'Sokha']);

        $entry = Activity::latest('id')->first();

        $this->assertSame(Activity::LOG_MODEL, $entry->log_name);
        $this->assertSame('created', $entry->event);
        $this->assertSame(Customer::class, $entry->subject_type);
        $this->assertSame($customer->id, $entry->subject_id);
        $this->assertSame($this->admin->id, $entry->causer_id);
        $this->assertStringContainsString('Sokha', $entry->description);
    }

    public function test_an_update_records_both_sides_of_the_change(): void
    {
        $this->actingAs($this->admin);

        $product = Product::factory()->create(['sell_price' => '2000']);
        $product->update(['sell_price' => '2500']);

        $changes = Activity::where('event', 'updated')->latest('id')->first()->attribute_changes;

        $this->assertSame('2000.00', $changes['old']['sell_price']);
        $this->assertSame('2500.00', $changes['attributes']['sell_price']);
    }

    /** An update that changes nothing is noise — dontLogEmptyChanges drops it. */
    public function test_an_update_that_changes_nothing_is_not_logged(): void
    {
        $this->actingAs($this->admin);

        $product = Product::factory()->create(['name' => 'Angkor']);
        $before = Activity::count();

        $product->update(['name' => 'Angkor']);

        $this->assertSame($before, Activity::count());
    }

    /** The audit trail must never become a place a password hash leaks to. */
    public function test_passwords_are_never_written_to_the_log(): void
    {
        $this->actingAs($this->admin);

        $user = User::factory()->create(['role' => 'cashier', 'store_id' => $this->store->id]);
        $user->update(['password' => 'a-brand-new-secret']);

        foreach (Activity::all() as $entry) {
            $blob = json_encode([$entry->attribute_changes, $entry->properties]);

            $this->assertStringNotContainsString('password', $blob);
            $this->assertStringNotContainsString('a-brand-new-secret', $blob);
        }
    }

    public function test_permission_changes_go_to_the_access_log(): void
    {
        $this->actingAs($this->admin);

        $cashier = User::factory()->create(['role' => 'cashier', 'store_id' => $this->store->id]);
        $cashier->update(['permissions' => [Permission::Reports->value => true]]);

        $entry = Activity::latest('id')->first();

        $this->assertSame(Activity::LOG_ACCESS, $entry->log_name);
        $this->assertSame(
            ['reports' => true],
            $entry->attribute_changes['attributes']['permissions'],
        );
    }

    public function test_signing_in_is_logged(): void
    {
        $this->post(route('login'), [
            'email' => $this->admin->email,
            'password' => 'password',
        ]);

        $entry = Activity::where('log_name', Activity::LOG_AUTH)->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('login', $entry->event);
        $this->assertSame($this->admin->id, $entry->causer_id);
    }

    /** A failed attempt has no causer, and must never record the password. */
    public function test_a_failed_sign_in_is_logged_without_the_password(): void
    {
        $this->post(route('login'), [
            'email' => $this->admin->email,
            'password' => 'the-wrong-one',
        ]);

        $entry = Activity::where('event', 'failed')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertNull($entry->causer_id);
        $this->assertSame($this->admin->email, $entry->properties['email']);
        $this->assertStringNotContainsString('the-wrong-one', json_encode($entry->properties));
    }

    public function test_the_screen_is_gated_by_the_activity_permission(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'is_active' => true,
            'store_id' => $this->store->id,
        ]);

        $this->actingAs($cashier)->get(route('activity.index'))->assertForbidden();

        // Managers do not hold it by default, but may be granted it.
        $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $this->actingAs($manager)->get(route('activity.index'))->assertForbidden();

        $manager->update(['permissions' => [Permission::Activity->value => true]]);
        $this->actingAs($manager)->get(route('activity.index'))->assertOk();
    }

    public function test_a_record_has_its_own_history_page(): void
    {
        $this->actingAs($this->admin);

        $product = Product::factory()->create(['name' => 'Angkor']);
        $product->update(['sell_price' => '4000']);

        $this->get(route('products.history', ['subjectId' => $product->uuid]))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Activity/Show')
                    ->where('subject.label', 'Angkor')
                    ->where('subject.exists', true)
                    ->has('entries.data', 2)
            );
    }

    /** A deleted record still has a history page, named from its last entry. */
    public function test_a_deleted_record_keeps_its_history_page(): void
    {
        $this->actingAs($this->admin);

        $product = Product::factory()->create(['name' => 'Gone Soon']);
        $id = $product->id;
        $product->delete();

        // The uuid died with the row; the numeric id is the address that
        // still finds the history it left behind.
        $this->get(route('products.history', ['subjectId' => $id]))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('subject.label', 'Gone Soon')
                    ->where('subject.exists', false)
            );
    }

    /** The endpoint is typed like show/edit — a non-numeric id is a 404. */
    public function test_a_malformed_history_url_is_a_404(): void
    {
        $this->actingAs($this->admin)
            ->get('/products/abc/history')
            ->assertNotFound();
    }

    public function test_the_history_page_is_gated_like_the_log(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'is_active' => true,
            'store_id' => $this->store->id,
        ]);

        $this->actingAs($cashier)
            ->get(route('products.history', ['subjectId' => 1]))
            ->assertForbidden();
    }

    public function test_the_screen_can_be_scoped_to_one_record(): void
    {
        $this->actingAs($this->admin);

        $watched = Product::factory()->create(['name' => 'Watched']);
        $watched->update(['sell_price' => '4000']);
        Product::factory()->create(['name' => 'Ignored']);

        $this->get(route('activity.index', [
            'filter' => ['subject_type' => 'Product', 'subject_id' => $watched->id],
        ]))->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Activity/Index')
                ->has('activities.data', 2)
                ->where('activities.data.0.subject_id', $watched->id)
        );
    }
}
