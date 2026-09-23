<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = preg_replace('/[\s()+-]/', '', (string) $this->input('whatsapp'));
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }
        $this->merge(['whatsapp' => $phone, 'email' => $this->filled('email') ? strtolower(trim($this->input('email'))) : null]);
    }

    public function rules(): array
    {
        return [
            'checkout_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:product_id,quantity,quoted_price'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.quoted_price' => ['required', 'integer', 'min:1', 'max:999999999'],
            'name' => ['required', 'string', 'max:120'],
            'whatsapp' => ['required', 'regex:/^[1-9][0-9]{7,14}$/'],
            'email' => ['nullable', 'email', 'max:254'],
            'fulfillment_method' => ['required', 'in:pickup,delivery'],
            // Delivery always ships from the delivery outlet, so only pickup chooses one.
            'outlet_id' => ['exclude_unless:fulfillment_method,pickup', 'required', 'integer', 'min:1'],
            'requested_date' => ['required', 'date_format:Y-m-d'],
            'requested_time' => ['required', 'date_format:H:i'],
            'delivery_address' => ['exclude_unless:fulfillment_method,delivery', 'required', 'string', 'max:2000'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'email.email' => 'Masukkan alamat email yang valid atau kosongkan.',
            'whatsapp.regex' => 'Masukkan nomor WhatsApp yang valid, misalnya 081234567890.',
            'items.min' => 'Pilih minimal satu produk.',
            'items.max' => 'Maksimal 50 produk dalam satu pesanan.',
            'items.*.product_id.distinct' => 'Produk sudah dipilih. Ubah jumlah pada baris yang sama.',
            'items.*.quantity.integer' => 'Jumlah harus berupa bilangan bulat.',
            'items.*.quantity.min' => 'Jumlah minimal 1.',
            'items.*.quantity.max' => 'Jumlah maksimal 99 per produk.',
            'requested_date.date_format' => 'Pilih tanggal PO yang valid.',
            'requested_time.date_format' => 'Pilih jam yang valid.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'Nama lengkap', 'whatsapp' => 'Nomor WhatsApp',
            'outlet_id' => 'Outlet', 'fulfillment_method' => 'Metode penerimaan',
            'requested_date' => 'Tanggal PO', 'requested_time' => 'Jam PO', 'delivery_address' => 'Alamat pengiriman',
            'items' => 'Pilihan produk', 'items.*.product_id' => 'Produk',
            'items.*.quantity' => 'Jumlah',
        ];
    }
}
