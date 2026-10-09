<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shop-wide settings: what the receipt says and which currency prices show in.
 *
 * Admin only. Everything else under /settings is about the signed-in person;
 * this screen changes what every cashier and every customer sees, which is a
 * decision for whoever owns the shop.
 */
class ShopController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/Shop', [
            'shop' => [
                'receipt_header' => Setting::get('receipt_header', config('app.name')),
                'receipt_footer' => Setting::get('receipt_footer'),
                'currency' => Currency::current()->code,
                'riel_per_usd' => Currency::current()->rielPerUsd,
                'order_prefix' => Setting::get('order_prefix'),
                'logo' => Setting::get('shop_logo'),
                'favicon' => Setting::get('shop_favicon'),
            ],
            'currencies' => collect(Currency::DEFINITIONS)
                ->map(fn (array $def, string $code) => [
                    'code' => $code,
                    'symbol' => $def['symbol'],
                    'name' => $def['name'],
                ])
                ->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        try {
            $data = $request->validate([
                'receipt_header' => ['required', 'string', 'max:120'],
                'receipt_footer' => ['nullable', 'string', 'max:255'],
                'currency' => ['required', Rule::in(array_keys(Currency::DEFINITIONS))],
                // A rate of 0 would turn every riel price into ៛0, and a rate in
                // the millions is a typo, not an economy. Bound it sensibly.
                'riel_per_usd' => ['required', 'numeric', 'min:1', 'max:100000'],

                // Leads every order number, so it has to survive the LIKE and
                // SUBSTRING_INDEX arithmetic the numbering is built on: letters,
                // digits and dashes, never a trailing dash or a space.
                'order_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/'],

                /*
                 * Branding. The favicon stays small and square-ish because the
                 * browser will render it at 16–32px — a 2MB photograph there
                 * is bandwidth spent on something nobody can see.
                 */
                'logo' => ['nullable', 'image', 'max:2048'],
                'favicon' => ['nullable', 'image', 'mimes:png,ico,webp,jpg,jpeg', 'max:512'],
                'remove_logo' => ['boolean'],
                'remove_favicon' => ['boolean'],
            ], [
                'order_prefix.regex' => 'Letters, numbers and dashes only — it leads every order number.',
            ]);

            foreach (['logo' => 'shop_logo', 'favicon' => 'shop_favicon'] as $field => $key) {
                $this->applyBrandingFile($request, $field, $key);
                unset($data[$field], $data['remove_'.$field]);
            }

            // All four settings land together or not at all — a currency
            // saved without its rate would misprice every screen at once.
            DB::transaction(function () use ($data) {
                foreach ($data as $key => $value) {
                    Setting::put($key, $value === null ? null : (string) $value);
                }
            });

            return back()->with('success', 'Shop settings saved.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The settings could not be saved. Nothing was changed — try again.');
        }
    }

    /**
     * Store, replace or remove one branding file.
     *
     * The old file is deleted only after the new path is safely in settings —
     * a failed upload must never leave the shop logo-less. Removal is its own
     * explicit flag; an empty file input on an ordinary save means "keep".
     */
    private function applyBrandingFile(Request $request, string $field, string $settingKey): void
    {
        $current = Setting::get($settingKey);

        if ($request->hasFile($field)) {
            $path = $request->file($field)->store('branding', 'public');
            Setting::put($settingKey, $path);

            if ($current && $current !== $path) {
                Storage::disk('public')->delete($current);
            }

            return;
        }

        if ($request->boolean('remove_'.$field) && $current) {
            Setting::put($settingKey, null);
            Storage::disk('public')->delete($current);
        }
    }
}
