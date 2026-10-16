<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    protected $fillable = [
        'name', 'short_name', 'slug', 'category', 'description', 'short_description',
        'default_note', 'price', 'daily_quota', 'image_path', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'daily_quota' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * URL gambar. Gambar bawaan (seed) ada di public/images/products,
     * gambar unggahan admin ada di storage publik (public/storage/products).
     */
    public function imageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        if (str_starts_with($this->image_path, 'images/')) {
            return asset($this->image_path);
        }

        return asset('storage/'.$this->image_path);
    }

    /**
     * Jumlah porsi per produk yang sudah terpesan untuk satu tanggal pengiriman
     * (pesanan yang dibatalkan tidak dihitung). Hasil: [product_id => porsi].
     *
     * @return array<int,int>
     */
    public static function reservedMap(string $date): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.delivery_date', $date)
            ->where('orders.status', '!=', Order::STATUS_CANCELLED)
            // pesanan yang belum dibayar hanya menahan kuota sampai batas waktu pembayarannya
            ->where(function ($q) {
                $q->where('orders.status', '!=', Order::STATUS_UNPAID)
                    ->orWhereNull('orders.payment_deadline')
                    ->orWhere('orders.payment_deadline', '>=', now());
            })
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as product_id, SUM(order_items.qty) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    public function remainingFor(string $date, ?array $reserved = null): int
    {
        $reserved ??= self::reservedMap($date);

        return max(0, $this->daily_quota - ($reserved[$this->id] ?? 0));
    }
}
