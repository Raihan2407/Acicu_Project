<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pesan WhatsApp otomatis ke pelanggan. Kegagalan kirim TIDAK PERNAH menggagalkan pesanan.
 * Driver diatur di .env (ACICU_WA_DRIVER): log | fonnte | off. Bawaan "log": hanya dicatat, tidak terkirim.
 */
final class WhatsAppNotifier
{
    public const EVENTS = ['created', 'paid', 'done', 'cancelled'];

    public static function driver(): string
    {
        return (string) config('acicu.notify.driver', 'log');
    }

    /** Kirim pesan untuk satu kejadian pesanan. Mengembalikan true bila dianggap terkirim/tercatat. */
    public static function forOrder(Order $order, string $event): bool
    {
        if (! in_array($event, self::EVENTS, true)) {
            return false;
        }

        $order->loadMissing('items');

        return self::send($order->whatsapp, self::message($order, $event));
    }

    /** Kirim teks bebas ke nomor 08xxxxxxxxxx / 628xxxxxxxxxx. */
    public static function send(string $phone, string $message): bool
    {
        $driver = self::driver();
        $phone = self::international($phone);

        if ($driver === 'off' || $phone === '') {
            return false;
        }

        try {
            if ($driver === 'fonnte') {
                $token = (string) config('acicu.notify.fonnte_token');
                if ($token === '') {
                    Log::warning('[WA] ACICU_FONNTE_TOKEN kosong, pesan tidak dikirim.');

                    return false;
                }

                $res = Http::timeout(6)
                    ->withHeaders(['Authorization' => $token])
                    ->asForm()
                    ->post('https://api.fonnte.com/send', ['target' => $phone, 'message' => $message, 'countryCode' => '62']);

                if (! $res->successful() || $res->json('status') === false) {
                    Log::warning('[WA] Fonnte menolak pesan', ['status' => $res->status(), 'body' => $res->body()]);

                    return false;
                }

                return true;
            }

            // driver "log": tidak ada pesan terkirim sungguhan
            Log::info("[WA:uji] ke {$phone}\n{$message}");

            return true;
        } catch (Throwable $e) {
            Log::warning('[WA] gagal mengirim: '.$e->getMessage());

            return false;
        }
    }

    public static function message(Order $order, string $event): string
    {
        $link = rtrim((string) config('acicu.frontend_url'), '/').'/pembayaran.html?t='.$order->tracking_token;
        $rp = fn (int $n) => 'Rp'.number_format($n, 0, ',', '.');
        $when = ($order->delivery_date?->format('d/m/Y') ?? '-').' pukul '.$order->delivery_time.' WIB';
        $head = "Halo {$order->customer_name}, ";
        $foot = "\n\n_ACICU - Aneka Aci Bungcu_";

        return match ($event) {
            'created' => $head."pesanan *{$order->po_number}* sudah kami terima.\n\n".
                $order->itemsSummary()."\nTotal bayar: *{$rp($order->total)}*\nKirim: {$when}\n\n".
                'Selesaikan pembayaran sebelum '.($order->payment_deadline?->timezone('Asia/Jakarta')->format('H:i') ?? '-').
                " WIB lewat tautan ini:\n{$link}".$foot,
            'paid' => $head."pembayaran pesanan *{$order->po_number}* sudah kami terima. Pesananmu masuk dapur ya!\n\nKirim: {$when}\nPantau status: {$link}".$foot,
            'done' => $head."pesanan *{$order->po_number}* sudah selesai diproses. Terima kasih sudah memesan di ACICU!".$foot,
            'cancelled' => $head."pesanan *{$order->po_number}* dibatalkan".($order->rejected_reason ? " ({$order->rejected_reason})" : '').
                ". Silakan pesan lagi kapan saja lewat situs ACICU atau hubungi admin.".$foot,
        };
    }

    /** 0812... -> 62812... (tanpa tanda baca). */
    private static function international(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($d, '0')) {
            $d = '62'.substr($d, 1);
        }

        return $d;
    }
}
