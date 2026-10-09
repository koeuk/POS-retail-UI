<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The shop's face: an uploaded logo for the sidebar and an icon for the
 * browser tab. Both live in settings as paths on the public disk.
 */
class ShopBrandingTest extends TestCase
{
    use RefreshDatabase;

    /** The other shop fields, valid — every save sends the whole form. */
    private function shopForm(array $overrides = []): array
    {
        return array_merge([
            'receipt_header' => 'My Shop',
            'receipt_footer' => null,
            'currency' => 'KHR',
            'riel_per_usd' => 4100,
        ], $overrides);
    }

    public function test_admin_can_upload_logo_and_favicon(): void
    {
        Storage::fake('public');

        $response = $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/shop', $this->shopForm([
                'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
                'favicon' => UploadedFile::fake()->image('icon.png', 64, 64),
            ]));

        $response->assertSessionHasNoErrors()->assertRedirect();

        $logo = Setting::get('shop_logo');
        $favicon = Setting::get('shop_favicon');

        $this->assertStringStartsWith('branding/', $logo);
        $this->assertStringStartsWith('branding/', $favicon);
        Storage::disk('public')->assertExists($logo);
        Storage::disk('public')->assertExists($favicon);
    }

    public function test_replacing_the_logo_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/settings/shop', $this->shopForm([
            'logo' => UploadedFile::fake()->image('first.png'),
        ]));
        $old = Setting::get('shop_logo');

        $this->actingAs($admin)->put('/settings/shop', $this->shopForm([
            'logo' => UploadedFile::fake()->image('second.png'),
        ]));

        $new = Setting::get('shop_logo');
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_saving_without_touching_files_keeps_the_logo(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/settings/shop', $this->shopForm([
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]));
        $path = Setting::get('shop_logo');

        // An ordinary save of the other fields — no file, no remove flag.
        $this->actingAs($admin)->put('/settings/shop', $this->shopForm([
            'receipt_header' => 'Renamed Shop',
        ]));

        $this->assertSame($path, Setting::get('shop_logo'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_remove_flag_clears_the_setting_and_deletes_the_file(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/settings/shop', $this->shopForm([
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]));
        $path = Setting::get('shop_logo');

        $this->actingAs($admin)->put('/settings/shop', $this->shopForm([
            'remove_logo' => true,
        ]));

        $this->assertNull(Setting::get('shop_logo'));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_oversized_favicon_is_rejected(): void
    {
        Storage::fake('public');

        $response = $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/shop', $this->shopForm([
                'favicon' => UploadedFile::fake()->image('huge.png')->size(600),
            ]));

        $response->assertSessionHasErrors('favicon');
        $this->assertNull(Setting::get('shop_favicon'));
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');

        $response = $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/shop', $this->shopForm([
                'logo' => UploadedFile::fake()->create('malware.pdf', 100, 'application/pdf'),
            ]));

        $response->assertSessionHasErrors('logo');
        $this->assertNull(Setting::get('shop_logo'));
    }

    public function test_uploaded_favicon_appears_in_the_document_head(): void
    {
        Setting::put('shop_favicon', 'branding/icon.png');

        $this->get('/login')
            ->assertOk()
            ->assertSee('storage/branding/icon.png');
    }

    public function test_no_favicon_tag_when_none_uploaded(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('rel="icon"', false);
    }

    public function test_cashier_cannot_change_branding(): void
    {
        Storage::fake('public');

        $this->actingAs(User::factory()->cashier()->create())
            ->put('/settings/shop', $this->shopForm([
                'logo' => UploadedFile::fake()->image('logo.png'),
            ]))
            ->assertForbidden();

        $this->assertNull(Setting::get('shop_logo'));
    }

    public function test_guests_receive_branding_so_the_login_page_can_show_the_logo(): void
    {
        Setting::put('shop_logo', 'branding/logo.png');

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('branding.logo', 'branding/logo.png'));
    }

    public function test_settings_page_shows_current_branding(): void
    {
        Setting::put('shop_logo', 'branding/logo.png');
        Setting::put('shop_favicon', 'branding/icon.png');

        $this->actingAs(User::factory()->admin()->create())
            ->get('/settings/shop')
            ->assertInertia(fn ($page) => $page
                ->component('settings/Shop')
                ->where('shop.logo', 'branding/logo.png')
                ->where('shop.favicon', 'branding/icon.png'));
    }
}
