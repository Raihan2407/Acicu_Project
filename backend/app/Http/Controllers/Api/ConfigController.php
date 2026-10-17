<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    /** Pengaturan publik untuk frontend: status toko, ongkir, slot jam, rekening, kontak. */
    public function show(): JsonResponse
    {
        $s = Settings::all();

        return response()->json(['data' => [
            'store_open' => (bool) $s['store_open'],
            'closed_message' => $s['closed_message'],
            'shipping_fee' => (int) $s['shipping_fee'],
            'delivery_slots' => array_values($s['delivery_slots']),
            'order_cutoff_minutes' => (int) $s['order_cutoff_minutes'],
            'max_advance_days' => (int) $s['max_advance_days'],
            'payment_ttl_minutes' => (int) $s['payment_ttl_minutes'],
            'require_proof' => (bool) $s['require_proof'],
            'today' => now()->toDateString(),
            'server_time' => now()->toIso8601String(),
            'batch_label' => $s['batch_label'],
            'payment' => [
                'bank_name' => $s['bank_name'],
                'bank_account' => $s['bank_account'],
                'bank_holder' => $s['bank_holder'],
                'qris_url' => $s['qris_image'] ? asset('storage/'.ltrim($s['qris_image'], '/')) : null,
            ],
            'contact' => [
                'whatsapp' => $s['whatsapp'],
                'whatsapp_display' => $s['whatsapp_display'],
                'instagram' => $s['instagram'],
                'tiktok' => $s['tiktok'],
                'admin_hours' => $s['admin_hours'],
            ],
        ]]);
    }
}
