<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\OwnerNotifier;
use App\Services\Presenter;
use App\Services\Settings;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /**
     * POST /api/orders (JSON)
     * Langkah 1: pelanggan mengisi form checkout. Pesanan dibuat dengan status "menunggu pembayaran"
     * lalu frontend membuka halaman pembayaran memakai tracking_token.
     */
    public function store(Request $request): JsonResponse
    {
        // Rapikan nomor WA sebelum divalidasi: "0812-3456-7890" / "+62 812..." -> "081234567890"
        $request->merge(['whatsapp' => $this->normalizeWhatsapp((string) $request->input('whatsapp', ''))]);

        if (! Settings::get('store_open')) {
            return response()->json(['message' => Settings::get('closed_message')], 422);
        }

        $slots = Settings::get('delivery_slots');
        $maxDate = now()->addDays((int) Settings::get('max_advance_days'))->toDateString();

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'whatsapp' => ['required', 'regex:/^08[0-9]{8,12}$/'],
            'address' => ['required', 'string', 'min:10', 'max:500'],
            'delivery_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.$maxDate],
            'delivery_time' => ['required', 'in:'.implode(',', $slots)],
            'notes' => ['nullable', 'string', 'max:300'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        // Slot hari ini yang sudah lewat batas pemesanan ditolak (memakai jam server, bukan jam perangkat).
        $slotTime = Carbon::parse($data['delivery_date'].' '.$data['delivery_time']);
        if ($slotTime->copy()->subMinutes((int) Settings::get('order_cutoff_minutes'))->isPast()) {
            throw ValidationException::withMessages([
                'delivery_time' => 'Slot jam ini sudah lewat batas pemesanan. Pilih jam lain atau tanggal berikutnya.',
            ]);
        }

        Order::expireOverdue(); // lepas kuota dari pesanan yang tidak dibayar

        $order = DB::transaction(function () use ($data) {
            // Kunci baris produk agar dua pelanggan tidak sama-sama mengambil kuota terakhir.
            $products = Product::query()
                ->whereIn('id', collect($data['items'])->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $reserved = Product::reservedMap($data['delivery_date']);
            $subtotal = 0;
            $lines = [];

            foreach ($data['items'] as $index => $line) {
                $product = $products->get((int) $line['product_id']);

                if (! $product || ! $product->is_active) {
                    throw ValidationException::withMessages([
                        "items.$index.product_id" => 'Menu tidak tersedia.',
                    ]);
                }

                $remaining = $product->remainingFor($data['delivery_date'], $reserved);
                if ($line['qty'] > $remaining) {
                    throw ValidationException::withMessages([
                        "items.$index.qty" => $remaining > 0
                            ? "Kuota {$product->short_name} untuk tanggal tersebut tersisa {$remaining} porsi."
                            : "Kuota {$product->short_name} untuk tanggal tersebut sudah habis.",
                    ]);
                }

                $lineTotal = $product->price * (int) $line['qty'];
                $subtotal += $lineTotal;
                $lines[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'qty' => (int) $line['qty'],
                    'unit_price' => $product->price,
                    'subtotal' => $lineTotal,
                    'note' => $product->default_note,
                ];
            }

            $shipping = (int) Settings::get('shipping_fee');

            $order = $this->createWithUniquePo([
                'tracking_token' => Str::random(40),
                'customer_name' => trim($data['name']),
                'whatsapp' => $data['whatsapp'],
                'address' => trim($data['address']),
                'delivery_date' => $data['delivery_date'],
                'delivery_time' => $data['delivery_time'],
                'notes' => isset($data['notes']) ? trim($data['notes']) : null,
                'subtotal' => $subtotal,
                'shipping_fee' => $shipping,
                'discount' => 0,
                'total' => $subtotal + $shipping,
                'payment_status' => Order::PAY_UNPAID,
                'status' => Order::STATUS_UNPAID,
                'payment_deadline' => now()->addMinutes((int) Settings::get('payment_ttl_minutes')),
            ]);

            $order->items()->createMany($lines);

            return $order;
        });

        $order->load('items.product');

        return response()->json(['data' => Presenter::publicOrder($order)], 201);
    }

    /** GET /api/orders/{token} : status pesanan untuk pelanggan (halaman pembayaran & status). */
    public function show(string $token): JsonResponse
    {
        Order::expireOverdue();

        $order = Order::query()
            ->with('items.product')
            ->where('tracking_token', $token)
            ->firstOrFail();

        return response()->json(['data' => Presenter::publicOrder($order)]);
    }

    /**
     * POST /api/orders/{token}/paid  (JSON atau multipart dengan berkas "proof")
     * Langkah 2: pelanggan menekan "Saya Sudah Bayar" di halaman pembayaran, boleh sambil mengunggah bukti
     * (screenshot transfer/QRIS). Bila pengaturan "require_proof" aktif, bukti wajib ada.
     * Pesanan masuk antrean "menunggu konfirmasi" untuk divalidasi admin.
     */
    public function markPaid(Request $request, string $token): JsonResponse
    {
        Order::expireOverdue();

        $order = Order::query()->with('items.product')->where('tracking_token', $token)->firstOrFail();

        if ($order->status === Order::STATUS_CANCELLED) {
            return response()->json(['message' => 'Waktu pembayaran sudah habis dan pesanan dibatalkan. Silakan buat pesanan baru.'], 422);
        }

        $request->validate([
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'proof.mimes' => 'Bukti harus berupa gambar JPG, PNG, atau WEBP.',
            'proof.max' => 'Ukuran bukti maksimal 2 MB.',
            'proof.uploaded' => 'Bukti gagal diunggah. Pastikan ukurannya maksimal 2 MB.',
        ]);

        if (Settings::get('require_proof') && ! $request->hasFile('proof') && ! $order->proof_path) {
            throw ValidationException::withMessages(['proof' => 'Anda belum mengunggah bukti pembayaran.']);
        }

        // Bukti hanya bisa diganti selama pembayaran belum divalidasi.
        if ($request->hasFile('proof') && in_array($order->status, [Order::STATUS_UNPAID, Order::STATUS_PENDING], true)) {
            $old = $order->proof_path;
            $order->proof_path = $request->file('proof')->store('proofs', 'public');
            $order->proof_uploaded_at = now();
            $order->save();

            if ($old) {
                Storage::disk('public')->delete($old);
            }
        }

        // Idempoten: menekan dua kali (atau setelah dikonfirmasi) tidak mengubah status lagi.
        if ($order->status === Order::STATUS_UNPAID) {
            $order->update([
                'status' => Order::STATUS_PENDING,
                'payment_status' => Order::PAY_WAITING,
                'customer_paid_at' => now(),
            ]);

            OwnerNotifier::paymentClaimed($order->fresh('items'));
        }

        return response()->json(['data' => Presenter::publicOrder($order->fresh('items.product'))]);
    }

    private function createWithUniquePo(array $attributes): Order
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return Order::create($attributes + ['po_number' => Order::nextPoNumber()]);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    private function normalizeWhatsapp(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }
}
