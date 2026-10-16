<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * QRIS dinamis: satu kode QR per pesanan dengan nominal terkunci, pembayaran terdeteksi otomatis.
 * Driver (ACICU_PAYMENT_DRIVER): manual | mock | midtrans.
 * Gagal membuat QR TIDAK menggagalkan pesanan: pelanggan tetap bisa memakai QRIS statis/transfer.
 */
final class PaymentGateway
{
    public static function driver(): string
    {
        $d = (string) config('acicu.gateway.driver', 'manual');

        return in_array($d, ['manual', 'mock', 'midtrans'], true) ? $d : 'manual';
    }

    public static function isDynamic(): bool
    {
        return self::driver() !== 'manual';
    }

    /** Buat QR untuk pesanan bila belum ada. Aman dipanggil berulang. */
    public static function charge(Order $order): void
    {
        if (! self::isDynamic() || $order->qr_string || $order->status !== Order::STATUS_UNPAID) {
            return;
        }

        if ($order->payment_deadline && $order->payment_deadline->isPast()) {
            return;
        }

        try {
            self::createQr($order);
        } catch (Throwable $e) {
            Log::warning('[PAY] gagal membuat QR untuk '.$order->po_number.': '.$e->getMessage());
        }
    }

    private static function createQr(Order $order): void
    {
        $expires = $order->payment_deadline ?? now()->addMinutes(60);

        if (self::driver() === 'mock') {
            $order->update([
                'payment_gateway' => 'mock',
                'payment_ref' => 'MOCK-'.$order->po_number.'-'.Str::upper(Str::random(5)),
                'qr_string' => 'ACICU-MOCK-QRIS|'.$order->po_number.'|'.$order->total.'|'.Str::upper(Str::random(16)),
                'qr_expires_at' => $expires,
            ]);

            return;
        }

        // midtrans: Core API, pembayaran QRIS
        $key = (string) config('acicu.gateway.midtrans_server_key');
        if ($key === '') {
            throw new \RuntimeException('ACICU_MIDTRANS_SERVER_KEY kosong');
        }

        $base = config('acicu.gateway.midtrans_production') ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
        $ref = 'ACICU-'.$order->id.'-'.Str::upper(Str::random(5));
        $minutes = max(1, (int) ceil(now()->diffInSeconds($expires, false) / 60));

        $res = Http::timeout(10)->withBasicAuth($key, '')->acceptJson()->post($base.'/v2/charge', [
            'payment_type' => 'qris',
            'transaction_details' => ['order_id' => $ref, 'gross_amount' => $order->total],
            'qris' => ['acquirer' => 'gopay'],
            'custom_expiry' => ['expiry_duration' => $minutes, 'unit' => 'minute'],
        ]);

        $body = $res->json() ?? [];
        if (! $res->successful() || ! in_array((string) ($body['status_code'] ?? ''), ['200', '201'], true)) {
            throw new \RuntimeException('Midtrans: '.($body['status_message'] ?? $res->status()));
        }

        $url = collect($body['actions'] ?? [])->firstWhere('name', 'generate-qr-code')['url'] ?? null;

        $order->update([
            'payment_gateway' => 'midtrans',
            'payment_ref' => $ref,
            'qr_string' => $body['qr_string'] ?? null,
            'qr_url' => $url,
            'qr_expires_at' => $expires,
        ]);
    }

    /**
     * Webhook Midtrans. Mengembalikan false bila tanda tangan tidak valid atau pesanan tidak ditemukan.
     * Tanda tangan: sha512(order_id + status_code + gross_amount + server_key).
     */
    public static function handleMidtrans(array $p): bool
    {
        $key = (string) config('acicu.gateway.midtrans_server_key');
        $expected = hash('sha512', ($p['order_id'] ?? '').($p['status_code'] ?? '').($p['gross_amount'] ?? '').$key);

        if ($key === '' || ! hash_equals($expected, (string) ($p['signature_key'] ?? ''))) {
            return false;
        }

        $order = Order::query()->where('payment_ref', $p['order_id'] ?? '')->first();
        if (! $order) {
            return false;
        }

        $paid = in_array($p['transaction_status'] ?? '', ['settlement', 'capture'], true);
        $amountOk = (int) round((float) ($p['gross_amount'] ?? 0)) === (int) $order->total;

        if ($paid && $amountOk) {
            self::markPaid($order, 'QRIS (otomatis)');
        }

        return true;
    }

    /** Tandai lunas oleh sistem (webhook / simulasi) lalu kirim notifikasi. Idempoten. */
    public static function markPaid(Order $order, string $method): void
    {
        if (! in_array($order->status, [Order::STATUS_UNPAID, Order::STATUS_PENDING], true)) {
            if ($order->status === Order::STATUS_CANCELLED) {
                Log::warning("[PAY] {$order->po_number} sudah dibatalkan tetapi pembayaran masuk: cek manual / refund.");
            }

            return;
        }

        $order->update([
            'payment_status' => Order::PAY_PAID,
            'status' => Order::STATUS_PROCESSING,
            'payment_method' => $method,
            'paid_at' => now(),
            'customer_paid_at' => $order->customer_paid_at ?? now(),
        ]);

        WhatsAppNotifier::forOrder($order->fresh('items'), 'paid');
    }
}
