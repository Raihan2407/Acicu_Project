<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Services\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    private const CATEGORIES = ['Aci Kering', 'Aci Kuah'];

    /** GET /api/admin/products?search=&category= */
    public function index(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $reserved = Product::reservedMap($today);

        $query = Product::query()->orderBy('sort_order')->orderBy('id');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('short_description', 'like', "%{$search}%"));
        }

        if (in_array($request->query('category'), self::CATEGORIES, true)) {
            $query->where('category', $request->query('category'));
        }

        $all = Product::query()->get();
        $products = $query->get()->map(fn (Product $p) => Presenter::adminProduct($p, $reserved[$p->id] ?? 0))->values();

        return response()->json([
            'data' => $products,
            'meta' => [
                'total' => $products->count(),
                'active_count' => $all->where('is_active', true)->count(),
                'categories' => self::CATEGORIES,
            ],
        ]);
    }

    /** POST /api/admin/products (multipart bila ada gambar) */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $product = new Product($data);
        $product->slug = $this->uniqueSlug($data['name']);
        $product->sort_order = (int) Product::max('sort_order') + 1;

        if ($request->hasFile('image')) {
            $product->image_path = $request->file('image')->store('products', 'public');
        }

        $product->save();

        ActivityLog::record($request->user(), 'product.create', "Menambah produk {$product->name}", ['product_id' => $product->id], $request);

        return response()->json(['data' => Presenter::adminProduct($product, 0)], 201);
    }

    /**
     * POST /api/admin/products/{id}  (dengan field _method=PUT bila mengunggah gambar)
     * PUT  /api/admin/products/{id}
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validated($request, $product);

        if ($product->name !== $data['name']) {
            $product->slug = $this->uniqueSlug($data['name'], $product->id);
        }

        $product->fill($data);

        if ($request->hasFile('image')) {
            $this->deleteUploadedImage($product);
            $product->image_path = $request->file('image')->store('products', 'public');
        }

        $product->save();

        ActivityLog::record($request->user(), 'product.update', "Mengubah produk {$product->name}", ['product_id' => $product->id], $request);

        $reserved = Product::reservedMap(now()->toDateString());

        return response()->json(['data' => Presenter::adminProduct($product, $reserved[$product->id] ?? 0)]);
    }

    /** PATCH /api/admin/products/{id}/stock : ubah cepat kuota harian dan/atau aktif-nonaktif */
    public function stock(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'daily_quota' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $product->update($data);

        ActivityLog::record($request->user(), 'product.stock', "Mengubah stok {$product->name}", ['product_id' => $product->id] + $data, $request);

        $reserved = Product::reservedMap(now()->toDateString());

        return response()->json(['data' => Presenter::adminProduct($product->fresh(), $reserved[$product->id] ?? 0)]);
    }

    /** DELETE /api/admin/products/{id} : pesanan lama tetap aman (nama & harga disimpan di order_items). */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        $name = $product->name;
        $this->deleteUploadedImage($product);
        $product->delete();

        ActivityLog::record($request->user(), 'product.delete', "Menghapus produk {$name}", ['product_id' => $product->id], $request);

        return response()->json(['message' => 'Produk dihapus.']);
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'short_name' => ['nullable', 'string', 'max:60'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'default_note' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:0', 'max:10000000'],
            'daily_quota' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        unset($data['image']);
        $data['short_name'] = $data['short_name'] ?? $product?->short_name ?? Str::words($data['name'], 1, '');
        $data['short_name'] = $data['short_name'] ?: $data['name'];
        $data['is_active'] = $request->boolean('is_active', $product?->is_active ?? true);

        return $data;
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'produk';
        $slug = $base;
        $i = 2;

        while (Product::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Hanya hapus gambar yang diunggah admin (gambar bawaan di public/images/products dibiarkan). */
    private function deleteUploadedImage(Product $product): void
    {
        if ($product->image_path && ! str_starts_with($product->image_path, 'images/')) {
            Storage::disk('public')->delete($product->image_path);
        }
    }
}
