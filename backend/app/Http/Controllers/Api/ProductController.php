<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Presenter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /** GET /api/products?date=YYYY-MM-DD : menu aktif + sisa kuota untuk tanggal tersebut. */
    public function index(Request $request): JsonResponse
    {
        $date = $this->resolveDate($request->query('date'));
        $reserved = Product::reservedMap($date);

        $products = Product::active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Product $p) => Presenter::product($p, $p->remainingFor($date, $reserved)))
            ->values();

        return response()->json([
            'data' => $products,
            'meta' => ['date' => $date],
        ]);
    }

    private function resolveDate(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
            } catch (\Throwable) {
                // jatuh ke hari ini
            }
        }

        return now()->toDateString();
    }
}
