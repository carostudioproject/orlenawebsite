<?php

namespace App\Http\Requests;

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
        return ['variant.unique' => 'A product with this name and variant already exists in that category.', 'hamper_contents.required' => 'List the hampers contents, one item per line.', 'sale_ends_on.after_or_equal' => 'The sale end date must be on or after the start date.', 'upload.image' => 'The photo must be a JPG, PNG or WebP image.', 'upload.mimes' => 'The photo must be a JPG, PNG or WebP image.', 'upload.max' => 'The photo may be at most 4 MB.'];
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return match ($this->route('resource')) {
            'categories' => [
                'name' => ['required', 'string', 'max:120', Rule::unique('categories')->ignore($id)],
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
                'is_delivery_hub' => ['sometimes', 'boolean'],
                'upload' => ContentImage::RULES,
            ],
            'products' => [
                'name' => ['required', 'string', 'max:160'],
                'sku' => ['required', 'alpha_dash:ascii', 'max:80', Rule::unique('products')->ignore($id)],
                'category_id' => ['required', 'integer', 'exists:categories,id'],
                // Products with the same name in a category are one item with variant choices on the order form.
                'variant' => ['nullable', 'string', 'max:40', Rule::unique('products')->ignore($id)
                    ->where(fn ($query) => $query->where('category_id', $this->input('category_id'))->where('name', $this->input('name')))],
                'is_hamper' => ['sometimes', 'boolean'],
                'hamper_contents' => [Rule::requiredIf($this->boolean('is_hamper')), 'nullable', 'string', 'max:3000'],
                'sale_starts_on' => ['nullable', 'date_format:Y-m-d'],
                'sale_ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:sale_starts_on'],
                'description' => ['nullable', 'string', 'max:5000'],
                'upload' => ContentImage::RULES,
                'price' => [Rule::requiredIf($this->boolean('is_active')), 'nullable', 'integer', 'min:1', 'max:999999999'],
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
