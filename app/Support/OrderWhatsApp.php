<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderAddition;

/**
 * The message a customer sends to Orlena after placing a pre-order. WhatsApp renders *text* in bold; plain ASCII symbols only, since emoji do not survive every WhatsApp client.
 * Address and email are left out on purpose; staff read them in the dashboard.
 */
class OrderWhatsApp
{
    public static function message(Order $order): string
    {
        $order->loadMissing('items', 'customer');
        $rupiah = fn (int $amount) => 'Rp '.number_format($amount, 0, ',', '.');
        $items = $order->items->map(fn ($item) => '- '.$item->quantity.'x '.$item->product_name_snapshot
            .($item->variant_snapshot ? ' ('.$item->variant_snapshot.')' : '').' = '.$rupiah($item->subtotal));
        $delivery = $order->fulfillment_method === 'delivery';
        $schedule = $order->requested_date->locale('id')->translatedFormat('l, j F Y')
            .($order->requested_time ? ', '.substr($order->requested_time, 0, 5).' WITA' : '');

        $lines = [
            'Halo Orlena,',
            'Saya sudah mengisi form pre-order dengan detail berikut:',
            '',
            '*Order Code:* '.$order->order_code,
            '*Nama:* '.$order->customer->name,
            '',
            '*Pesanan*',
            ...$items,
            '',
            '*Subtotal:* '.$rupiah($order->subtotal),
            '*Ongkir:* '.($delivery ? 'Mengikuti tarif Gojek/Grab (dikonfirmasi admin)' : 'Tidak ada'),
            '*Penerimaan:* '.($delivery ? 'Delivery via Gojek/Grab dari '.$order->outlet_name_snapshot : 'Pickup di '.$order->outlet_name_snapshot),
            '*Jadwal:* '.$schedule,
        ];
        if ($order->customer_note) {
            $lines[] = '*Catatan:* '.$order->customer_note;
        }
        if ($order->card_message) {
            $lines[] = '*Kartu ucapan:* '.$order->card_message;
        }

        return implode("\n", [...$lines, '', 'Mohon dicek dan dikonfirmasi ya. Terima kasih.']);
    }

    /** Update after the customer adds items: only the additions and the new total. */
    public static function additionMessage(Order $order, OrderAddition $addition): string
    {
        $rupiah = fn (int $amount) => 'Rp '.number_format($amount, 0, ',', '.');
        $items = collect($addition->items)->map(fn ($item) => '- '.$item['quantity'].'x '.$item['name']
            .(! empty($item['variant']) ? ' ('.$item['variant'].')' : '').' = '.$rupiah($item['subtotal']));

        return implode("\n", [
            'Halo Orlena,',
            'Saya menambah pesanan untuk *Order Code:* '.$order->order_code,
            '',
            '*Tambahan*',
            ...$items,
            '',
            '*Tambahan subtotal:* '.$rupiah($addition->subtotal_added),
            '*Total awal baru:* '.$rupiah($addition->new_total).($order->delivery_fee === null ? ' (belum termasuk ongkir)' : ''),
            '',
            'Mohon dicek kembali ya. Terima kasih.',
        ]);
    }
}
