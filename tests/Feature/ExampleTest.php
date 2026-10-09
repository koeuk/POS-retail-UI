<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * There is no landing page — this is staff software. The root URL is a
 * router, not a destination.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_sends_guests_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_root_sends_signed_in_staff_to_their_home_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }
}
