<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    /** Authorisation is handled by ProductPolicy via authorizeResource(). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')],

            /*
             * Pack sizes are one level deep on purpose. A pack of a pack would
             * need the base units multiplied down a chain, and no shop counts
             * stock that way — a case is 24 cans, full stop.
             *
             * Set directly only when something creates a pack row on its own;
             * the form builds packs through the `packs` array below instead.
             */
            'parent_product_id' => [
                'nullable', 'integer',
                Rule::exists('products', 'id')->whereNull('parent_product_id'),
                Rule::notIn(array_filter([$productId])),
            ],
            'units_per_pack' => ['required_with:parent_product_id', 'integer', 'min:1', 'max:100000'],

            /*
             * Optional larger sizes of this same product, entered inline: a
             * beer bought 264 cans at a time and sold by the twelve, the six
             * and the single is one product with three extra prices, and
             * making the shopkeeper create three more products by hand is how
             * the stock figures end up disagreeing.
             */
            'packs' => ['array', 'max:20'],
            'packs.*.id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'packs.*.name' => ['required', 'string', 'max:255'],
            'packs.*.units_per_pack' => ['required', 'integer', 'min:1', 'max:100000'],
            'packs.*.sell_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'packs.*.barcode' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required', 'string', 'max:64',
                Rule::unique('products', 'sku')->ignore($productId),
            ],
            'barcode' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'barcode')->ignore($productId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            // One price, and it is what the customer pays. No cost, no tax.
            'sell_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'unit' => ['required', 'string', 'max:20'],
            // Units per case, for counting the shelf only — a case is not sold.
            'case_size' => ['nullable', 'integer', 'min:2', 'max:100000'],
            'track_stock' => ['boolean'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'max:2048'],
            // Pasted link as an alternative to uploading; a real upload wins.
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],

            // Gallery: extra photos, uploaded and/or pasted as links. On an
            // edit, gallery_existing is the kept subset of what was saved —
            // anything missing from it is being removed.
            'gallery' => ['nullable', 'array', 'max:12'],
            'gallery.*' => ['image', 'max:2048'],
            'gallery_urls' => ['nullable', 'array', 'max:12'],
            'gallery_urls.*' => ['url:http,https', 'max:2048'],
            'gallery_existing' => ['nullable', 'array', 'max:12'],
            'gallery_existing.*' => ['string', 'max:2048'],

            // Opening stock, only meaningful on create.
            'opening_qty' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],

            /*
             * Goods received, from the product screen rather than a trip to
             * Inventory. Recorded as a real Restock movement, never as a raw
             * write to stocks.qty — the ledger is the point of that page.
             */
            'add_stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],

            /*
             * A delivery arrives the way it is packed: three cases and a
             * hundred loose packets, not 172 packets. The pack says what the
             * quantity above is counted in; `add_stock_loose` carries the
             * remainder in single units so both go in on one save.
             */
            'add_stock_pack_id' => ['nullable', 'integer', Rule::exists('products', 'id')],

            /*
             * How many units are in each of the things being received, when
             * the container is not one of the sellable packs. A shop that buys
             * beer by the 24 but only ever sells singles should not have to
             * invent a priced "case" on the POS grid just to book a delivery.
             */
            'add_stock_units_each' => ['nullable', 'integer', 'min:1', 'max:100000'],

            /*
             * What to call the container — "set", "case", "carton". Purely a
             * label: it never becomes a pack and never reaches the POS grid.
             * It exists so the movement reads "10 × 24 cans" in the ledger
             * rather than a bare number nobody can picture.
             */
            'add_stock_unit_label' => ['nullable', 'string', 'max:30'],

            'add_stock_loose' => ['nullable', 'integer', 'min:0', 'max:1000000'],

            'add_stock_store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')],
            'add_stock_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'That SKU is already used by another product.',
            'barcode.unique' => 'That barcode is already used by another product.',
            'parent_product_id.exists' => 'A pack must belong to a product that is not itself a pack.',
            'parent_product_id.not_in' => 'A product cannot be a pack of itself.',
            'units_per_pack.required_with' => 'Say how many units this pack contains.',
            'packs.*.name.required' => 'Give each pack size a name.',
            'packs.*.units_per_pack.required' => 'Say how many units the pack contains.',
            'packs.*.units_per_pack.min' => 'A pack has to hold at least one.',
            'packs.*.sell_price.required' => 'Give each pack size a price.',
            'case_size.min' => 'A case has to hold at least two, or there is nothing to count.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'track_stock' => $this->boolean('track_stock'),
            'is_active' => $this->boolean('is_active'),
            'barcode' => $this->input('barcode') ?: null,
            // An emptied field arrives as '' — that means "count singles", not zero.
            'case_size' => $this->input('case_size') ?: null,
        ]);

        // '' from an emptied select means "no vendor"; an absent key leaves it be.
        if ($this->has('vendor_id')) {
            $this->merge(['vendor_id' => $this->input('vendor_id') ?: null]);
        }

        /*
         * Only normalise the pack keys when the caller actually sent one.
         *
         * Merging them unconditionally meant any edit that did not mention
         * them — the product form no longer does, since packs are managed as
         * inline rows on the parent — silently reset parent_product_id to null
         * and units_per_pack to 1, turning a case of 24 into a standalone
         * product and orphaning its stock arithmetic.
         */
        if ($this->has('parent_product_id') || $this->has('units_per_pack')) {
            $parentId = $this->input('parent_product_id') ?: null;

            $this->merge([
                'parent_product_id' => $parentId,
                // A base product always contains one of itself.
                'units_per_pack' => $parentId ? $this->input('units_per_pack') : 1,
            ]);
        }
    }
}
