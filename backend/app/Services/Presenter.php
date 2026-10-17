<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Carbon\Carbon;

/** Pengubah model menjadi array JSON. Satu tempat agar bentuk respons API konsisten. */
final class Presenter
{
    public static function product(Product $product, int $remaining): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'short_name' => $product->short_name,
            'slug' => $product->slug,
            'category' => $product->category,
            'description' => $product->description,
            'short_description' => $product->short_description,
            'default_note' => $product->default_note,
            'price' => $product->price,
            'image_url' => $product->imageUrl(),
            'remaining' => $remaining,
            'available' => $remaining > 0,
        ];
    }

    public static function adminProduct(Product $product, int $reservedToday): array
    {
        $remaining = max(0, $product->daily_quota - $reservedToday);

        $status = ! $product->is_active ? 'nonaktif' : ($remaining === 0 ? 'habis' : 'tersedia');

        return self::product($product, $remaining) + [
            'daily_quota' => $product->daily_quota,
            'reserved_today' => $reservedToday,
            'is_active' => $product->is_active,
            'sort_order' => $product->sort_order,
            'status' => $status,
        ];
    }

    /** Baris ringkas untuk tabel Data Pesanan (admin). */
    public static function orderRow(Order $order): array
    {
        return [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'customer_name' => $order->customer_name,
            'whatsapp' => $order->whatsapp,
            'whatsapp_link' => $order->whatsappLink(),
            'items' => $order->items->map(fn ($i) => [
                'product_name' => $i->product_name,
                'qty' => $i->qty,
                'note' => $i->note,
            ])->values(),
            'items_summary' => $order->itemsSummary(),
            'portions' => $order->portions(),
            'net_total' => $order->netTotal(),
            'total' => $order->total,
            'ordered_at' => $order->created_at?->toIso8601String(),
            'delivery_date' => $order->delivery_date?->format('Y-m-d'),
            'delivery_time' => $order->delivery_time,
            'status' => $order->status,
            'status_label' => $order->statusLabel(),
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'payment_deadline' => $order->payment_deadline?->toIso8601String(),
            'customer_paid_at' => $order->customer_paid_at?->toIso8601String(),
            'has_proof' => (bool) $order->proof_path,
        ];
    }

    /** Detail lengkap satu pesanan (admin). */
    public static function orderDetail(Order $order): array
    {
        return self::orderRow($order) + [
            'address' => $order->address,
            'notes' => $order->notes,
            'subtotal' => $order->subtotal,
            'shipping_fee' => $order->shipping_fee,
            'discount' => $order->discount,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'rejected_reason' => $order->rejected_reason,
            'validated_by' => $order->validator?->name,
            'proof_url' => $order->proofUrl(),
            'proof_uploaded_at' => $order->proof_uploaded_at?->toIso8601String(),
        ];
    }

    /** Data untuk halaman status pesanan pelanggan (tanpa alamat, nomor WA disamarkan). */
    public static function publicOrder(Order $order): array
    {
        $deliveryTime = $order->delivery_time;
        $kitchenEta = Carbon::createFromFormat('H:i', $deliveryTime)->subMinutes(90)->format('H:i');

        return [
            'tracking_token' => $order->tracking_token,
            'po_number' => $order->po_number,
            'customer_name' => $order->customer_name,
            'whatsapp_masked' => $order->maskedWhatsapp(),
            'ordered_at' => $order->created_at?->toIso8601String(),
            'delivery_date' => $order->delivery_date?->format('Y-m-d'),
            'delivery_time' => $deliveryTime,
            'kitchen_eta' => $kitchenEta,
            'notes' => $order->notes,
            'status' => $order->status,
            'status_label' => $order->statusLabel(),
            'payment_status' => $order->payment_status,
            'payment_deadline' => $order->payment_deadline?->toIso8601String(),
            'customer_paid_at' => $order->customer_paid_at?->toIso8601String(),
            'rejected_reason' => $order->rejected_reason,
            'proof_url' => $order->proofUrl(),
            'proof_uploaded_at' => $order->proof_uploaded_at?->toIso8601String(),
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->product_name,
                'qty' => $i->qty,
                'unit_price' => $i->unit_price,
                'subtotal' => $i->subtotal,
                'note' => $i->note,
                'image_url' => $i->product?->imageUrl(),
            ])->values(),
            'subtotal' => $order->subtotal,
            'shipping_fee' => $order->shipping_fee,
            'discount' => $order->discount,
            'total' => $order->total,
        ];
    }

    /** Baris tabel Laporan Penjualan. */
    public static function transaction(Order $order): array
    {
        return [
            'transaction_id' => $order->transactionId(),
            'po_number' => $order->po_number,
            'ordered_at' => $order->created_at?->toIso8601String(),
            'customer_name' => $order->customer_name,
            'whatsapp_masked' => $order->maskedWhatsapp(),
            'items_summary' => $order->itemsSummary(),
            'portions' => $order->portions(),
            'payment_method' => $order->payment_method ?? '-',
            'net_total' => $order->netTotal(),
            'status' => 'Lunas',
        ];
    }

    public static function paginationMeta($paginator): array
    {
        return [
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
