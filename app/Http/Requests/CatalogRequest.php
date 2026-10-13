<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Support\ContentImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-catalog') ?? false;
    }

    public function messages(): array
    {
        return ['variant.unique' => 'A product with this name and variant already exists in that category.', 'barcode.unique' => 'Another product already uses this barcode.', 'price.min' => 'An active product needs a price above Rp 0. Box flavours at Rp 0 stay inactive.', 'barcode.regex' => 'The barcode cannot contain spaces.', 'hamper_contents.required' => 'List the hampers contents, one item per line.', 'content_ids.required' => 'Choose at least one product the customer can pick as contents.', 'content_ids.min' => 'Choose at least one product the customer can pick as contents.', 'sale_ends_on.after_or_equal' => 'The sale end date must be on or after the start date.', 'upload.image' => 'The photo must be a JPG, PNG or WebP image.', 'upload.mimes' => 'The photo must be a JPG, PNG or WebP image.', 'upload.max' => 'The photo may be at most 4 MB.', 'upload.dimensions' => 'The photo may be at most 6000 × 6000 pixels.'];
    }

    public function rules(): array
    {
        $id = $this->route('id');
        // Hampers and boxes keep a list of contents; a fixed box (set) lists its flavours, repeated once per piece.
        $type = (string) $this->input('content_type');
        $withContents = in_array($type, ['hampers', 'box', 'set'], true);
        $contentRule = Rule::exists('products', 'id')->where(fn ($query) => $type === 'hampers'
            ? $query->whereNull('content_type')
            : $query->where(fn ($q) => $q->whereNull('content_type')->orWhere('content_type', 'flavour')));

        return match ($this->route('resource')) {
            'categories' => [
                'name' => ['required', 'string', 'max:120', Rule::unique('categories')->ignore($id)],
                'order_position' => ['sometimes', 'integer', 'min:0', 'max:999'],
                'is_active' => ['sometimes', 'boolean'],
                'upload' => ContentImage::RULES,
            ],
            'outlets' => [
                'name' => ['required', 'string', 'max:160'],
                'code' => ['required', 'alpha_dash:ascii', 'max:80', Rule::unique('outlets')->ignore($id)],
                'address' => ['required', 'string', 'max:2000'],
                'maps_url' => ['nullable', 'url:https', 'max:1000'],
                'is_active' => ['required', 'boolean'],
                'accepts_preorder' => ['sometimes', 'boolean'],
                'delivers' => ['sometimes', 'boolean'],
                // Rules Order Website: daily quota (100, 30 at malls), pickups per hourly slot, and the slot hours.
                'daily_quota' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
                'hourly_quota' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'],
                'first_slot' => ['sometimes', 'required', 'date_format:H:i'],
                'closes_at' => ['sometimes', 'required', 'date_format:H:i', 'after:first_slot'],
                'upload' => ContentImage::RULES,
            ],
            'products' => [
                'name' => ['required', 'string', 'max:160'],
                'sku' => ['required', 'alpha_dash:ascii', 'max:80', Rule::unique('products')->ignore($id)],
                // Erzap matches order items by barcode, so it must be the same as in Erzap and unique.
                'barcode' => ['nullable', 'string', 'max:80', 'regex:/^\S+$/', Rule::unique('products')->ignore($id)],
                'category_id' => ['required', 'integer', 'exists:categories,id'],
                // Products with the same name in a category are one item with variant choices on the order form.
                'variant' => ['nullable', 'string', 'max:40', Rule::unique('products')->ignore($id)
                    ->where(fn ($query) => $query->where('category_id', $this->input('category_id'))->where('name', $this->input('name')))],
                'is_hamper' => ['sometimes', 'boolean'],
                'hamper_contents' => [Rule::requiredIf($this->boolean('is_hamper') && ! $withContents), 'nullable', 'string', 'max:3000'],
                // Hampers (customer picks 1 item, price added), box (picks N), set (fixed flavours) or flavour (only inside a box).
                'content_type' => ['nullable', Rule::in(array_keys(Product::TYPES))],
                'content_count' => [Rule::excludeIf(! in_array($type, ['hampers', 'box'], true)), 'required', 'integer', 'min:1', 'max:50'],
                'content_pricing' => [Rule::excludeIf(! in_array($type, ['hampers', 'box'], true)), 'required', Rule::in(array_keys(Product::CONTENT_PRICING))],
                'content_ids' => [Rule::excludeIf(! $withContents), 'required', 'array', 'min:1', 'max:500'],
                'content_ids.*' => ['integer', ...($type === 'set' ? [] : ['distinct']), $contentRule],
                'sale_starts_on' => ['nullable', 'date_format:Y-m-d'],
                'sale_ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:sale_starts_on'],
                'description' => ['nullable', 'string', 'max:5000'],
                'upload' => ContentImage::RULES,
                // Rp 0 is for box flavours (sold only inside a box); those stay inactive.
                'price' => [Rule::requiredIf($this->boolean('is_active')), 'nullable', 'integer', $this->boolean('is_active') ? 'min:1' : 'min:0', 'max:999999999'],
                'is_active' => ['required', 'boolean'],
                // Multipart uploads omit empty arrays, so a missing list means "no outlet overrides".
                'outlet_prices' => ['sometimes', 'array', 'max:100'],
                'outlet_prices.*' => ['array:outlet_id,price'],
                'outlet_prices.*.outlet_id' => ['required', 'integer', 'distinct', 'exists:outlets,id'],
                'outlet_prices.*.price' => ['required', 'integer', 'min:1', 'max:999999999'],
            ],
            default => [],
        };
    }
}
