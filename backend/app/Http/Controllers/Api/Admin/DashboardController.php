<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    private const DAY_LABELS = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

    /** GET /api/admin/dashboard : ringkasan kartu, tren 7 hari, aktivitas terbaru. */
    public function index(): JsonResponse
    {
        Order::expireOverdue();

        $today = now()->startOfDay();
        $from = $today->copy()->subDays(6);

        $orders = Order::query()
            ->with('items')
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->where('created_at', '>=', $from)
            ->get();

        // siapkan 7 hari (hari ini di paling kanan)
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $from->copy()->addDays($i);
            $days[$date->toDateString()] = [
                'date' => $date->toDateString(),
                'label' => self::DAY_LABELS[$date->dayOfWeek],
                'orders' => 0,
                'portions' => 0,
                'revenue' => 0,
            ];
        }

        $productPortions = [];
        $revenue = 0;

        foreach ($orders as $order) {
            $key = $order->created_at->toDateString();
            if (! isset($days[$key])) {
                continue;
            }

            $days[$key]['orders']++;
            $days[$key]['portions'] += $order->portions();

            if ($order->payment_status === Order::PAY_PAID) {
                $days[$key]['revenue'] += $order->netTotal();
                $revenue += $order->netTotal();
            }

            foreach ($order->items as $item) {
                $productPortions[$item->product_name] = ($productPortions[$item->product_name] ?? 0) + $item->qty;
            }
        }

        arsort($productPortions);
        $topName = array_key_first($productPortions);

        $ordersToday = $days[$today->toDateString()]['orders'];
        $ordersYesterday = $days[$today->copy()->subDay()->toDateString()]['orders'];
        $change = $ordersYesterday > 0
            ? (int) round(($ordersToday - $ordersYesterday) / $ordersYesterday * 100)
            : null;

        $trend = array_values($days);
        $sortedByOrders = $trend;
        usort($sortedByOrders, fn ($a, $b) => $b['orders'] <=> $a['orders']);
        $peak = array_slice($sortedByOrders, 0, 3);
        usort($peak, fn ($a, $b) => strcmp($a['date'], $b['date']));

        $recent = Order::query()
            ->with('items')
            ->latest('created_at')
            ->latest('id')
            ->limit(3)
            ->get()
            ->map(function (Order $o) {
                $first = $o->items->first();
                $title = $o->items->count() > 1
                    ? ($first?->product_name.' +'.($o->items->count() - 1).' menu')
                    : ($first ? $first->product_name.' ('.$first->qty.' Porsi)' : '-');

                return [
                    'id' => $o->id,
                    'po_number' => $o->po_number,
                    'title' => $title,
                    'customer_name' => $o->customer_name,
                    'ordered_at' => $o->created_at?->toIso8601String(),
                    'status' => $o->status,
                    'payment_status' => $o->payment_status,
                ];
            })->values();

        return response()->json(['data' => [
            'today' => $today->toDateString(),
            'batch_label' => \App\Services\Settings::get('batch_label'),
            'cards' => [
                'orders_today' => ['count' => $ordersToday, 'change_percent' => $change],
                'to_process' => [
                    'count' => Order::query()
                        ->where('delivery_date', $today->toDateString())
                        ->inQueue()
                        ->count(),
                ],
                'top_product' => [
                    'name' => $topName,
                    'portions' => $topName ? $productPortions[$topName] : 0,
                ],
                'revenue' => ['amount' => $revenue, 'period' => '7 hari terakhir'],
            ],
            'trend' => $trend,
            'insight' => [
                'peak_dates' => array_column($peak, 'date'),
                'peak_labels' => array_column($peak, 'label'),
                'average_orders' => (int) round(array_sum(array_column($trend, 'orders')) / 7),
            ],
            'recent' => $recent,
        ]]);
    }
}
