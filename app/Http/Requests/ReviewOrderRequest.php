<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review-orders') ?? false;
    }

    public function rules(): array
    {
        return [
            'review_version' => ['required', 'integer', 'min:0'],
            'delivery_fee' => ['present', 'nullable', 'integer', 'min:0', 'max:999999999'],
            'note' => ['required', 'string', 'max:2000'],
            'requested_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'requested_time' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Write the review result or why the delivery fee changed.',
            'note.max' => 'Catatan maksimal 2000 karakter.',
            'delivery_fee.integer' => 'The delivery fee must be a whole rupiah amount.',
            'delivery_fee.min' => 'The delivery fee cannot be negative.',
            'delivery_fee.max' => 'The delivery fee is above the allowed limit.',
            'requested_date.required' => 'Tanggal PO wajib diisi.',
            'requested_date.date_format' => 'Choose a valid PO date.',
            'requested_time.date_format' => 'Choose a valid time.',
        ];
    }
}
