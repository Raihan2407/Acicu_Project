<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUS_UNPAID = 'menunggu_pembayaran';   // baru checkout, belum membayar
    public const STATUS_PENDING = 'menunggu_konfirmasi';  // pelanggan sudah bayar, menunggu dicek admin
    public const STATUS_PROCESSING = 'proses_dapur';
    public const STATUS_DONE = 'selesai';
    public const STATUS_CANCELLED = 'dibatalkan';

    public const PAY_UNPAID = 'belum_bayar';
    public const PAY_WAITING = 'menunggu';
    public const PAY_PAID = 'lunas';
    public const PAY_REJECTED = 'ditolak';

    public const STATUS_LABELS = [
        self::STATUS_UNPAID => 'Menunggu Pembayaran',
        self::STATUS_PENDING => 'Menunggu Konfirmasi',
        self::STATUS_PROCESSING => 'Proses Dapur',
        self::STATUS_DONE => 'Selesai',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    protected $fillable = [
        'po_number', 'tracking_token', 'customer_name', 'whatsapp', 'address',
        'delivery_date', 'delivery_time', 'notes', 'subtotal', 'shipping_fee', 'discount', 'total',
        'payment_method', 'payment_status', 'status', 'payment_deadline', 'customer_paid_at',
        'rejected_reason', 'paid_at', 'validated_by',
        'payment_gateway', 'payment_ref', 'qr_string', 'qr_url', 'qr_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date:Y-m-d',
            'subtotal' => 'integer',
            'shipping_fee' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'payment_deadline' => 'datetime',
            'customer_paid_at' => 'datetime',
            'paid_at' => 'datetime',
            'qr_expires_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /** Antrean dapur: sudah dibayar pelanggan (menunggu dicek) atau sedang diproses. */
    public function scopeInQueue(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    /** Pesanan yang sudah dibayar (dipakai untuk laporan keuangan). */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('payment_status', self::PAY_PAID);
    }

    /** Pesanan yang menunggu admin mengecek pembayaran (badge di menu). */
    public function scopeAwaitingConfirmation(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Batalkan otomatis pesanan yang tidak dibayar sampai batas waktunya.
     * Dipanggil saat pesanan/produk dibaca, jadi tidak perlu scheduler.
     */
    public static function expireOverdue(): int
    {
        return static::query()
            ->where('status', self::STATUS_UNPAID)
            ->whereNotNull('payment_deadline')
            ->where('payment_deadline', '<', now())
            ->update([
                'status' => self::STATUS_CANCELLED,
                'rejected_reason' => 'Pembayaran melewati batas waktu',
                'updated_at' => now(),
            ]);
    }

    /** Pendapatan bersih makanan (tidak termasuk ongkir). */
    public function netTotal(): int
    {
        return max(0, $this->subtotal - $this->discount);
    }

    public function portions(): int
    {
        return (int) $this->items->sum('qty');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function itemsSummary(): string
    {
        return $this->items
            ->map(fn (OrderItem $item) => $item->qty.'x '.$item->product_name)
            ->implode(', ');
    }

    /** 0812-3456-xxxx : nomor disamarkan untuk halaman publik & laporan. */
    public function maskedWhatsapp(): string
    {
        $digits = preg_replace('/\D+/', '', $this->whatsapp) ?? '';

        if (strlen($digits) < 8) {
            return 'xxxx';
        }

        return substr($digits, 0, 4).'-'.substr($digits, 4, 4).'-xxxx';
    }

    /** Link wa.me (format internasional, 08xx -> 628xx). */
    public function whatsappLink(): string
    {
        $digits = preg_replace('/\D+/', '', $this->whatsapp) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }

        return 'https://wa.me/'.$digits;
    }

    public function transactionId(): string
    {
        return 'TRX-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /** Nomor PO berikutnya: PO-0001, PO-0002, ... (lanjut dari angka terbesar). */
    public static function nextPoNumber(): string
    {
        $max = static::query()->pluck('po_number')
            ->map(fn (string $po) => (int) preg_replace('/\D+/', '', $po))
            ->max() ?? 0;

        return 'PO-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
