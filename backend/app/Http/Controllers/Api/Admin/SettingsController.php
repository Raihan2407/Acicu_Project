<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Pengaturan toko yang dikelola tim (hanya owner): jam kirim, ongkir, rekening, QRIS, kontak, buka/tutup pre-order. */
class SettingsController extends Controller
{
    /** GET /api/admin/settings */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    /** POST|PUT /api/admin/settings (multipart bila mengunggah QRIS) */
    public function update(Request $request): JsonResponse
    {
        // izinkan slot dikirim sebagai string "06:00,07:00" maupun array
        if (is_string($request->input('delivery_slots'))) {
            $request->merge(['delivery_slots' => array_values(array_filter(array_map('trim', explode(',', $request->input('delivery_slots')))))]);
        }
        if ($request->has('whatsapp')) {
            $request->merge(['whatsapp' => $this->normalizeWhatsapp((string) $request->input('whatsapp'))]);
        }
        foreach (['store_open', 'remove_qris', 'require_proof'] as $flag) {
            if ($request->has($flag)) {
                $request->merge([$flag => filter_var($request->input($flag), FILTER_VALIDATE_BOOLEAN)]);
            }
        }

        $data = $request->validate([
            'store_open' => ['sometimes', 'boolean'],
            'closed_message' => ['sometimes', 'nullable', 'string', 'max:200'],
            'batch_label' => ['sometimes', 'string', 'max:60'],
            'shipping_fee' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'delivery_slots' => ['sometimes', 'array', 'min:1', 'max:48'],
            'delivery_slots.*' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/', 'distinct'],
            'order_cutoff_minutes' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'max_advance_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'payment_ttl_minutes' => ['sometimes', 'integer', 'min:10', 'max:1440'],
            'bank_name' => ['sometimes', 'string', 'max:30'],
            'bank_account' => ['sometimes', 'string', 'max:40'],
            'bank_holder' => ['sometimes', 'string', 'max:80'],
            'qris' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_qris' => ['sometimes', 'boolean'],
            'require_proof' => ['sometimes', 'boolean'],
            'whatsapp' => ['sometimes', 'regex:/^62[0-9]{8,13}$/'],
            'whatsapp_display' => ['sometimes', 'string', 'max:20'],
            'instagram' => ['sometimes', 'nullable', 'string', 'max:40', Rule::notIn(['@'])],
            'tiktok' => ['sometimes', 'nullable', 'string', 'max:40'],
            'admin_hours' => ['sometimes', 'string', 'max:40'],
        ], [
            'whatsapp.regex' => 'Nomor WhatsApp harus berformat 08xxxxxxxxxx atau 62xxxxxxxxxx.',
            'delivery_slots.*.regex' => 'Format jam harus HH:MM, contoh 06:00.',
            'delivery_slots.*.distinct' => 'Ada jam yang ditulis dua kali.',
        ]);

        $values = collect($data)->except(['qris', 'remove_qris'])->all();

        if (isset($values['delivery_slots'])) {
            $slots = $values['delivery_slots'];
            sort($slots);
            $values['delivery_slots'] = array_values($slots);
        }
        foreach (['instagram', 'tiktok'] as $handle) {
            if (isset($values[$handle])) {
                $values[$handle] = ltrim(trim($values[$handle]), '@');
            }
        }

        if ($request->hasFile('qris')) {
            $this->deleteQris();
            $values['qris_image'] = $request->file('qris')->store('qris', 'public');
        } elseif ($request->boolean('remove_qris')) {
            $this->deleteQris();
            $values['qris_image'] = null;
        }

        Settings::set($values);

        // catat kunci yang berubah saja (tanpa menyimpan isi rekening ke log)
        ActivityLog::record($request->user(), 'settings.update', 'Mengubah pengaturan toko', ['keys' => array_keys($values)], $request);

        return response()->json(['data' => $this->payload()]);
    }

    private function payload(): array
    {
        $s = Settings::all();
        $s['qris_url'] = $s['qris_image'] ? asset('storage/'.ltrim($s['qris_image'], '/')) : null;
        return $s;
    }

    private function deleteQris(): void
    {
        $path = Settings::get('qris_image');
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function normalizeWhatsapp(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        return $digits;
    }
}
