<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\Setting;
use App\Services\PricingService;
use Inertia\Inertia;

class DineMenuController extends Controller
{
    public function __construct(
        private PricingService $pricingService,
    ) {}

    public function show(string $token)
    {
        $table = DiningTable::where('token', $token)
            ->where('is_active', true)
            ->with('area')
            ->firstOrFail();
        $outlet = $table->area?->outlet;

        $selfOrderEnabled = Setting::getBool('dine_in_self_order_enabled', true);

        // Kategori dan produk TIDAK punya kolom is_active/sort_order. SQLite
        // membaca nama kolom yang tidak dikenal sebagai teks (menu kosong
        // tanpa galat); MySQL/TiDB menolaknya — menu meja jatuh 500.
        $categories = Category::query()
            ->orderBy('name')
            ->get();

        $products = Product::query()
            ->where(function ($query) use ($outlet) {
                $query->where('stock', '>', 0);
                if ($outlet) {
                    $query->orWhereHas('warehouses', fn ($warehouse) => $warehouse->where('outlet_id', $outlet->id)->where('stock', '>', 0));
                }
            })
            // `units` sudah berisi model Unit (belongsToMany); `units.unit`
            // meminta relasi yang tidak ada dan menjatuhkan menu bersatuan ganda.
            ->with(['category', 'units'])
            ->get();

        // ponytail: previewProducts() returns arrays keyed by product id (pricing fields only) — merge the effective price back onto model-shaped payloads the menu page expects (id/title/image/category_id/sell_price)
        $previews = $this->pricingService->previewProducts($products, null, null, $outlet);

        $products = $products->map(function (Product $product) use ($previews) {
            $preview = $previews->get($product->id);

            return [
                'id' => $product->id,
                'title' => $product->title,
                'image' => $product->image,
                'category_id' => $product->category_id,
                'sell_price' => (int) ($preview['effective_unit_price'] ?? $product->sell_price),
            ];
        })->values();

        $storeName = Setting::getForOutlet('store_name', $outlet, 'Restoran');
        $storeLogo = Setting::getForOutlet('store_logo', $outlet);

        return Inertia::render('Public/DineMenu', [
            'table' => [
                'id' => $table->id,
                'token' => $table->token,
                'name' => $table->name,
                'capacity' => $table->capacity,
                'area_name' => $table->area?->name,
            ],
            'categories' => $categories,
            'products' => $products,
            'selfOrderEnabled' => $selfOrderEnabled,
            'storeName' => $storeName,
            'storeLogo' => $storeLogo,
        ]);
    }
}
