<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\SaleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a batch flush from the offline queue.
 *
 * Rules are deliberately permissive about *business* outcomes — an order whose
 * quantity exceeds stock is still valid input, because the sale already
 * happened. What is enforced here is structural: the payload must describe a
 * real, complete sale so the sync service can record it faithfully.
 */
class SyncOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A tablet offline all day can still only hold so much; cap the
            // batch so one request cannot run the server out of memory.
            'orders' => ['required', 'array', 'min:1', 'max:200'],

            'orders.*.client_uuid' => ['required', 'string', 'max:36', 'distinct'],
            'orders.*.store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')],
            'orders.*.register_id' => ['nullable', 'integer', Rule::exists('registers', 'id')],
            'orders.*.customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')],
            'orders.*.sale_type' => ['nullable', Rule::enum(SaleType::class)],
            'orders.*.created_offline_at' => ['nullable', 'date'],
            'orders.*.discount_amount' => ['nullable', 'numeric', 'min:0'],

            'orders.*.items' => ['required', 'array', 'min:1'],
            'orders.*.items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'orders.*.items.*.product_name' => ['nullable', 'string', 'max:255'],
            'orders.*.items.*.qty' => ['required', 'integer', 'min:1'],
            'orders.*.items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'orders.*.items.*.discount' => ['nullable', 'numeric', 'min:0'],

            'orders.*.payments' => ['required', 'array', 'min:1'],
            'orders.*.payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'orders.*.payments.*.amount' => ['required', 'numeric', 'min:0'],
            'orders.*.payments.*.reference_no' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'orders.*.client_uuid.distinct' => 'The same order appears twice in one batch.',
            'orders.max' => 'Flush at most 200 orders per request.',
        ];
    }

    /**
     * A debt with no customer is money nobody can collect. The rule is
     * cross-field, so it lives here rather than in the flat rules array.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ($this->input('orders', []) as $i => $order) {
                if (($order['sale_type'] ?? null) === SaleType::Debt->value && empty($order['customer_id'])) {
                    $v->errors()->add("orders.{$i}.customer_id", 'A sale on debt must be attached to a customer.');
                }
            }
        });
    }
}
