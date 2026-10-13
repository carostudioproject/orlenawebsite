<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    /** A Google Maps link as shared from the app or browser. */
    public const MAPS_URL = '~^https://((www\.)?google\.[a-z.]+/maps|maps\.google\.[a-z.]+|maps\.app\.goo\.gl|goo\.gl/maps)([/?#]|$)~i';

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
        $recipient = preg_replace('/[\s()+-]/', '', (string) $this->input('recipient_whatsapp'));
        if (str_starts_with($recipient, '0')) {
            $recipient = '62'.substr($recipient, 1);
        }
        $this->merge([
            'whatsapp' => $phone, 'recipient_whatsapp' => $recipient, 'email' => $this->filled('email') ? strtolower(trim($this->input('email'))) : null,
            'location_url' => trim((string) $this->input('location_url')),
            // Only delivery can leave the outlet to Orlena (the nearest one).
            'nearest_outlet' => $this->input('fulfillment_method') === 'delivery' && $this->boolean('nearest_outlet'),
        ]);
    }

    /**
     * Cart lines, shared with "Tambah pesanan". The same hampers or box may appear on several lines with different contents;
     * contents are the product IDs chosen for one unit (repeats allowed, checked against the product in OrderPricing).
     */
    public static function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:product_id,quantity,quoted_price,contents'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.quoted_price' => ['required', 'integer', 'min:1', 'max:999999999'],
            'items.*.contents' => ['sometimes', 'array', 'max:50'],
            'items.*.contents.*' => ['integer', 'min:1'],
        ];
    }

    public function rules(): array
    {
        return [
            'checkout_key' => ['required', 'uuid'],
            ...self::itemRules(),
            'name' => ['required', 'string', 'max:120'],
            'whatsapp' => ['required', 'regex:/^[1-9][0-9]{7,14}$/'],
            'email' => ['nullable', 'email', 'max:254'],
            'fulfillment_method' => ['required', 'in:pickup,delivery'],
            // Pickup at the chosen outlet; delivery from the chosen outlet or the nearest one, picked by Orlena.
            'nearest_outlet' => ['boolean'],
            'outlet_id' => ['exclude_if:nearest_outlet,true', 'required', 'integer', 'min:1'],
            'requested_date' => ['required', 'date_format:Y-m-d'],
            'requested_time' => ['required', 'date_format:H:i'],
            'delivery_address' => ['exclude_unless:fulfillment_method,delivery', 'required', 'string', 'max:2000'],
            'recipient_whatsapp' => ['exclude_unless:fulfillment_method,delivery', 'required', 'regex:/^[1-9][0-9]{7,14}$/'],
            'location_url' => ['exclude_unless:fulfillment_method,delivery', 'required', 'string', 'max:500', 'url:https', 'regex:'.self::MAPS_URL],
            'customer_note' => ['nullable', 'string', 'max:2000'],
            'card_message' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'email.email' => 'Masukkan alamat email yang valid atau kosongkan.',
            'whatsapp.regex' => 'Masukkan nomor WhatsApp yang valid, misalnya 081234567890.',
            'recipient_whatsapp.regex' => 'Masukkan nomor WhatsApp penerima yang valid, misalnya 081234567890.',
            'location_url.url' => 'Tempel link lokasi dari Google Maps (diawali https://).',
            'location_url.regex' => 'Tempel link lokasi dari Google Maps (diawali https://maps.app.goo.gl/ atau https://www.google.com/maps/).',
            'items.min' => 'Pilih minimal satu produk.',
            'items.max' => 'Maksimal 50 produk dalam satu pesanan.',
            'items.*.quantity.integer' => 'Jumlah harus berupa bilangan bulat.',
            'items.*.quantity.min' => 'Jumlah minimal 1.',
            'items.*.quantity.max' => 'Jumlah maksimal 99 per produk.',
            'requested_date.date_format' => 'Pilih tanggal PO yang valid.',
            'card_message.max' => 'Pesan kartu ucapan maksimal 300 karakter.',
            'requested_time.date_format' => 'Pilih jam yang valid.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'Nama lengkap', 'whatsapp' => 'Nomor WhatsApp',
            'outlet_id' => 'Outlet', 'fulfillment_method' => 'Metode penerimaan',
            'requested_date' => 'Tanggal PO', 'requested_time' => 'Jam PO', 'delivery_address' => 'Alamat pengiriman', 'recipient_whatsapp' => 'WhatsApp penerima', 'location_url' => 'Link lokasi Google Maps',
            'items' => 'Pilihan produk', 'items.*.product_id' => 'Produk',
            'items.*.quantity' => 'Jumlah',
        ];
    }
}
