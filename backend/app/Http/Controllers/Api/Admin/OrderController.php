<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Services\Presenter;
use App\Services\WhatsAppNotifier;
use App\Services\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrderController extends Controller
{
    private const PAYMENT_METHODS = ['QRIS', 'Transfer BCA', 'Transfer Bank Lain'];

    /** GET /api/admin/orders?search=&date=YYYY-MM-DD&status=&page=&per_page= */
    public function index(Request $request): JsonResponse
    {
        Order::expireOverdue();

        $perPage = min(100, max(5, (int) $request->query('per_page', 10)));

        $paginator = $this->filtered($request)
            ->with('items')
            // pesanan yang menunggu konfirmasi pembayaran selalu di paling atas
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [Order::STATUS_PENDING])
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Order $o) => Presenter::orderRow($o))->values(),
            'meta' => Presenter::paginationMeta($paginator) + [
                'queue_total' => Order::query()->inQueue()->count(),
                'pending_count' => Order::query()->awaitingConfirmation()->count(),
            ],
        ]);
    }

    /** GET /api/admin/orders/{order} */
    public function show(Order $order): JsonResponse
    {
        $order->load(['items', 'validator']);

        return response()->json(['data' => Presenter::orderDetail($order)]);
    }

    /**
     * POST /api/admin/orders/{order}/validate
     * Admin sudah mengecek mutasi/QRIS dan uangnya masuk -> lunas & masuk dapur.
     * Boleh juga untuk pesanan yang belum menekan "Saya Sudah Bayar" (mis. bayar lalu konfirmasi lewat WhatsApp).
     */
    public function validatePayment(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'in:'.implode(',', self::PAYMENT_METHODS)],
        ]);

        if (! in_array($order->status, [Order::STATUS_UNPAID, Order::STATUS_PENDING], true)) {
            return response()->json(['message' => 'Pesanan ini sudah dikonfirmasi atau dibatalkan.'], 422);
        }

        $order->update([
            'payment_status' => Order::PAY_PAID,
            'status' => Order::STATUS_PROCESSING,
            'payment_method' => $data['payment_method'] ?? 'QRIS / Transfer',
            'paid_at' => now(),
            'validated_by' => $request->user()->id,
        ]);

        ActivityLog::record($request->user(), 'order.validate', "Mengonfirmasi pembayaran {$order->po_number}", ['order_id' => $order->id], $request);
        WhatsAppNotifier::forOrder($order->fresh('items'), 'paid');

        return response()->json(['data' => Presenter::orderDetail($order->fresh(['items', 'validator']))]);
    }

    /** POST /api/admin/orders/{order}/reject : pembayaran tidak ditemukan -> pesanan dibatalkan (kuota kembali). */
    public function rejectPayment(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        if (! in_array($order->status, [Order::STATUS_UNPAID, Order::STATUS_PENDING], true)) {
            return response()->json(['message' => 'Pesanan ini sudah dikonfirmasi atau dibatalkan.'], 422);
        }

        $order->update([
            'payment_status' => Order::PAY_REJECTED,
            'status' => Order::STATUS_CANCELLED,
            'rejected_reason' => $data['reason'] ?? null,
            'validated_by' => $request->user()->id,
        ]);

        ActivityLog::record($request->user(), 'order.reject', "Menolak pembayaran {$order->po_number}", ['order_id' => $order->id], $request);
        WhatsAppNotifier::forOrder($order->fresh('items'), 'cancelled');

        return response()->json(['data' => Presenter::orderDetail($order->fresh(['items', 'validator']))]);
    }

    /** PATCH /api/admin/orders/{order}/status : proses_dapur | selesai | dibatalkan */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', [Order::STATUS_PROCESSING, Order::STATUS_DONE, Order::STATUS_CANCELLED])],
        ]);

        $new = $data['status'];

        if ($order->payment_status !== Order::PAY_PAID && $new !== Order::STATUS_CANCELLED) {
            return response()->json(['message' => 'Konfirmasi pembayaran dulu sebelum memproses pesanan.'], 422);
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            return response()->json(['message' => 'Pesanan yang dibatalkan tidak bisa diubah.'], 422);
        }

        $order->update(['status' => $new]);

        ActivityLog::record($request->user(), 'order.status', "Mengubah status {$order->po_number} menjadi {$order->statusLabel()}", ['order_id' => $order->id, 'status' => $new], $request);

        if ($new === Order::STATUS_DONE) {
            WhatsAppNotifier::forOrder($order->fresh('items'), 'done');
        } elseif ($new === Order::STATUS_CANCELLED) {
            WhatsAppNotifier::forOrder($order->fresh('items'), 'cancelled');
        }

        return response()->json(['data' => Presenter::orderDetail($order->fresh(['items', 'validator']))]);
    }

    /** GET /api/admin/orders/export : unduh .xlsx sesuai filter yang sedang dipilih. */
    public function export(Request $request): BinaryFileResponse
    {
        Order::expireOverdue();

        $orders = $this->filtered($request)
            ->with('items')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(5000)
            ->get();

        $rows = [];
        foreach ($orders as $i => $o) {
            $rows[] = [
                $i + 1,
                $o->po_number,
                $o->created_at?->format('d/m/Y H:i'),
                $o->customer_name,
                $o->whatsapp,
                $o->address,
                $o->itemsSummary(),
                $o->portions(),
                $o->netTotal(),
                $o->shipping_fee,
                $o->total,
                $o->delivery_date?->format('d/m/Y'),
                $o->delivery_time,
                $o->statusLabel(),
                $o->payment_status,
                $o->payment_method ?? '-',
            ];
        }

        $path = XlsxWriter::build(
            'Data Pesanan',
            ['No', 'Nomor PO', 'Waktu Pesan', 'Nama Pelanggan', 'WhatsApp', 'Alamat', 'Rincian Menu', 'Porsi',
                'Subtotal (Rp)', 'Ongkir (Rp)', 'Total Bayar (Rp)', 'Tgl Kirim', 'Slot Kirim', 'Status', 'Pembayaran', 'Metode'],
            $rows,
            ['ACICU - Data Pesanan Pre-Order', 'Dibuat '.now()->format('d/m/Y H:i').' WIB oleh '.$request->user()->name],
            [8, 9, 10],
        );

        ActivityLog::record($request->user(), 'order.export', 'Mengunduh data pesanan (.xlsx)', ['rows' => count($rows)], $request);

        return response()
            ->download($path, 'data-pesanan-acicu-'.now()->format('Ymd-His').'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    private function filtered(Request $request): Builder
    {
        $query = Order::query();

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(fn (Builder $q) => $q
                ->where('po_number', 'like', $like)
                ->orWhere('customer_name', 'like', $like)
                ->orWhere('whatsapp', 'like', $like));
        }

        $date = $request->query('date');
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $query->where('delivery_date', $date);
        }

        $status = $request->query('status');
        if (is_string($status) && array_key_exists($status, Order::STATUS_LABELS)) {
            $query->where('status', $status);
        }

        return $query;
    }
}
