<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Presenter;
use App\Services\XlsxWriter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Laporan keuangan: hanya untuk peran "owner" (dijaga di routes/api.php). */
class ReportController extends Controller
{
    /** GET /api/admin/reports?from=&to=&page=&per_page= */
    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $perPage = in_array((int) $request->query('per_page'), [10, 25, 50, 100], true)
            ? (int) $request->query('per_page')
            : 25;

        $base = $this->query($from, $to);

        $paginator = (clone $base)
            ->with('items')
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'data' => [
                'period' => ['from' => $from, 'to' => $to],
                'summary' => $this->summary($base),
                'transactions' => $paginator->getCollection()->map(fn (Order $o) => Presenter::transaction($o))->values(),
            ],
            'meta' => Presenter::paginationMeta($paginator),
        ]);
    }

    /** GET /api/admin/reports/export?from=&to= : unduh laporan .xlsx */
    public function export(Request $request): BinaryFileResponse
    {
        [$from, $to] = $this->range($request);
        $base = $this->query($from, $to);
        $summary = $this->summary($base);

        $orders = (clone $base)->with('items')->orderBy('created_at')->orderBy('id')->limit(10000)->get();

        $rows = [];
        foreach ($orders as $o) {
            $rows[] = [
                $o->transactionId(),
                $o->po_number,
                $o->created_at?->format('d/m/Y H:i'),
                $o->customer_name,
                $o->maskedWhatsapp(), // nomor disamarkan di laporan
                $o->itemsSummary(),
                $o->portions(),
                $o->payment_method ?? '-',
                $o->netTotal(),
                'Lunas',
            ];
        }

        $path = XlsxWriter::build(
            'Laporan Penjualan',
            ['ID Transaksi', 'Nomor PO', 'Tanggal & Jam', 'Nama Pelanggan', 'WhatsApp', 'Rincian Menu', 'Porsi', 'Metode', 'Total (Rp)', 'Status'],
            $rows,
            [
                'ACICU - Laporan Penjualan & Rekapitulasi Pre-Order',
                'Periode '.Carbon::parse($from)->format('d/m/Y').' s/d '.Carbon::parse($to)->format('d/m/Y')
                    .' | Pesanan: '.$summary['orders'].' | Porsi: '.$summary['portions'].' | Pendapatan bersih: Rp'.number_format($summary['revenue'], 0, ',', '.'),
            ],
            [8],
        );

        ActivityLog::record($request->user(), 'report.export', "Mengunduh laporan penjualan {$from} s/d {$to}", ['rows' => count($rows)], $request);

        return response()
            ->download($path, "laporan-penjualan-acicu-{$from}_{$to}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /** Hanya pesanan yang sudah dibayar (lunas), berdasarkan tanggal pesan. */
    private function query(string $from, string $to): Builder
    {
        return Order::query()
            ->paid()
            ->where('created_at', '>=', $from.' 00:00:00')
            ->where('created_at', '<=', $to.' 23:59:59');
    }

    private function summary(Builder $base): array
    {
        $orders = (clone $base)->count();
        $revenue = (int) ((clone $base)->selectRaw('COALESCE(SUM(subtotal - discount), 0) as revenue')->value('revenue') ?? 0);
        $portions = (int) OrderItem::query()
            ->whereIn('order_id', (clone $base)->select('orders.id'))
            ->sum('qty');

        return ['orders' => $orders, 'portions' => $portions, 'revenue' => $revenue];
    }

    /** @return array{0:string,1:string} */
    private function range(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $to = $request->query('to') ?: now()->toDateString();
        $from = $request->query('from') ?: Carbon::parse($to)->subDays(6)->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        // batasi rentang maksimal 1 tahun agar ekspor tidak membebani server
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            $from = Carbon::parse($to)->subDays(366)->toDateString();
        }

        return [$from, $to];
    }
}
