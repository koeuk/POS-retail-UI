<?php

namespace App\Http\Requests;

use App\Enums\Action;
use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;
        $isCreate = $userId === null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => [
                $isCreate ? 'required' : 'nullable',
                'confirmed',
                Password::defaults(),
            ],
            /*
             * Only an admin may mint another admin — otherwise anyone who was
             * granted the Staff screen could promote themselves through a
             * second account.
             */
            'role' => [
                'required',
                Rule::enum(Role::class),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === Role::Admin->value && ! $this->user()->isAdmin()) {
                        $fail('Only an administrator can grant the admin role.');
                    }

                    // A vendor hires cashiers only. Its own account keeps its
                    // role — the controller pins that on a self-edit.
                    $isSelf = $this->route('user')?->id === $this->user()->id;
                    if ($this->user()->hasRole(Role::Vendor) && ! $isSelf && $value !== Role::Cashier->value) {
                        $fail('A vendor account can only create cashiers.');
                    }
                },
            ],

            /*
             * Per-user feature overrides, {"reports": true}. Meaningless for
             * admins (they always hold everything), so it is dropped for an
             * admin target in prepareForValidation.
             */
            'permissions' => ['nullable', 'array'],
            // Either shape is valid: `true` (the whole area) or a per-action
            // map. prepareForValidation has already normalised whichever
            // arrived, so only the leaf values need checking here.
            'permissions.*' => ['array'],
            'permissions.*.*' => ['boolean'],

            /*
             * store_id is nullable in the schema because admins are not bound
             * to a store, but a cashier without one cannot open /pos at all —
             * there is no way to resolve which stock rows to read. So it is
             * conditionally required on role.
             */
            'store_id' => [
                Rule::requiredIf(fn () => $this->input('role') === Role::Cashier->value),
                'nullable',
                'integer',
                Rule::exists('stores', 'id'),
            ],
            // Required for a vendor account; optional for a cashier (the
            // vendor team they work for); meaningless for anyone else.
            'vendor_id' => [
                Rule::requiredIf(fn () => $this->input('role') === Role::Vendor->value),
                'nullable',
                'integer',
                Rule::exists('vendors', 'id'),
            ],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'store_id.required' => 'A cashier must be assigned to a store.',
            'vendor_id.required' => 'A vendor account must belong to a vendor.',
        ];
    }

    /**
     * One override, in the matrix shape.
     *
     * A plain `true`/`false` (the original shape, and what an older client
     * still sends) becomes that answer for every action, so both forms mean
     * the same thing and nothing has to be migrated.
     *
     * @return array<string, bool>
     */
    private static function normaliseActions(mixed $value): array
    {
        if (! is_array($value)) {
            return array_fill_keys(Action::values(), (bool) $value);
        }

        return collect($value)
            ->only(Action::values())
            ->map(fn ($granted) => (bool) $granted)
            ->all();
    }

    protected function prepareForValidation(): void
    {
        $permissions = $this->input('permissions');

        if (is_array($permissions)) {
            // Unknown keys are dropped rather than rejected — a stale tab
            // sending a key that has since been renamed should not 422.
            $permissions = collect($permissions)
                ->only(Permission::values())
                ->map(fn ($value) => self::normaliseActions($value))
                ->all();
        }

        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'store_id' => $this->input('store_id') ?: null,
            'vendor_id' => in_array($this->input('role'), [Role::Vendor->value, Role::Cashier->value], true)
                ? ($this->input('vendor_id') ?: null)
                : null,
            // Admins hold everything regardless, so store no overrides.
            'permissions' => $this->input('role') === Role::Admin->value ? null : $permissions,
        ]);
    }
}
