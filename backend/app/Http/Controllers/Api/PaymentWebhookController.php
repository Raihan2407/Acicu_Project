<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    /**
     * POST /api/payments/midtrans/notification
     * Dipanggil server Midtrans saat status pembayaran berubah. Tanda tangan diverifikasi; permintaan palsu ditolak.
     * Daftarkan URL ini di dashboard Midtrans > Settings > Payment > Notification URL.
     */
    public function midtrans(Request $request): JsonResponse
    {
        if (PaymentGateway::driver() !== 'midtrans') {
            return response()->json(['message' => 'Tidak aktif.'], 404);
        }

        return PaymentGateway::handleMidtrans($request->all())
            ? response()->json(['message' => 'ok'])
            : response()->json(['message' => 'Tanda tangan tidak valid atau pesanan tidak ditemukan.'], 403);
    }
}
