<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Memberi tahu pemilik saat ada pembayaran yang menunggu validasi.
 * Memakai Telegram Bot API (resmi, gratis, memang dibuat untuk pesan otomatis), bukan WhatsApp.
 * Gagal kirim TIDAK PERNAH menggagalkan pesanan. Bawaan "off": pemilik memantau lewat panel admin.
 */
final class OwnerNotifier
{
    public static function driver(): string
    {
        return (string) config('acicu.owner_notify.driver', 'off');
    }

    public static function paymentClaimed(Order $order): void
    {
        $order->loadMissing('items');

        $text = "Pembayaran menunggu validasi\n"
            ."{$order->po_number} - {$order->customer_name}\n"
            .$order->itemsSummary()."\n"
            .'Total Rp'.number_format($order->total, 0, ',', '.')."\n"
            .'Kirim '.($order->delivery_date?->format('d/m/Y') ?? '-').' pukul '.$order->delivery_time."\n"
            .($order->proof_path ? 'Bukti: sudah diunggah' : 'Bukti: belum diunggah');

        self::send($text);
    }

    public static function send(string $text): bool
    {
        $driver = self::driver();

        try {
            if ($driver === 'telegram') {
                $token = (string) config('acicu.owner_notify.telegram_bot_token');
                $chat = (string) config('acicu.owner_notify.telegram_chat_id');

                if ($token === '' || $chat === '') {
                    Log::warning('[Owner] token/chat id Telegram belum diisi, pesan tidak dikirim.');

                    return false;
                }

                $res = Http::timeout(6)->asForm()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chat,
                    'text' => $text,
                ]);

                if (! $res->successful()) {
                    Log::warning('[Owner] Telegram menolak pesan', ['status' => $res->status()]);

                    return false;
                }

                return true;
            }

            if ($driver === 'log') {
                Log::info("[Owner:uji]\n{$text}");

                return true;
            }
        } catch (Throwable $e) {
            Log::warning('[Owner] gagal mengirim: '.$e->getMessage());
        }

        return false;
    }
}
