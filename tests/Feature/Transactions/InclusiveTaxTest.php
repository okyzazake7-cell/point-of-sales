<?php

namespace Tests\Feature\Transactions;

use App\Models\Category;
use App\Models\Product;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pajak "Inclusive" berarti harga jual SUDAH memuat PPN-nya. Kasir dulu
 * tetap menambahkan PPN di atas harga itu: barang Rp8.000 ditagih Rp8.880,
 * sama dengan "Exclusive" — panduan halaman Produk menjanjikan sebaliknya.
 */
class InclusiveTaxTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function produk(string $jenisPajak): Product
    {
        self::$seq++;
        $category = Category::create([
            'name' => 'Kategori Pajak '.self::$seq,
            'description' => 'Uji pajak',
            'image' => 'category.png',
        ]);

        return Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'PAJAK-'.self::$seq,
            'sku' => 'SKU-PAJAK-'.self::$seq,
            'title' => 'Teh Botol '.self::$seq,
            'description' => 'Uji pajak',
            'buy_price' => 6000,
            'sell_price' => 8000,
            'stock' => 10,
            'tax_rate' => 11,
            'tax_type' => $jenisPajak,
        ]);
    }

    private function ringkasan(Product $produk): array
    {
        return app(LoyaltyService::class)->previewCheckout([
            'summary' => ['base_subtotal' => 8000, 'subtotal_after_promo' => 8000],
            'items' => [['product_id' => $produk->id, 'line_total' => 8000]],
        ])['summary'];
    }

    public function test_inclusive_tax_is_not_charged_again_on_top_of_the_price(): void
    {
        $ringkasan = $this->ringkasan($this->produk('inclusive'));

        $this->assertSame(8000, $ringkasan['grand_total']);
        $this->assertSame(0, $ringkasan['tax_total']);
    }

    public function test_exclusive_tax_is_added_to_the_price(): void
    {
        $ringkasan = $this->ringkasan($this->produk('exclusive'));

        $this->assertSame(8880, $ringkasan['grand_total']);
        $this->assertSame(880, $ringkasan['tax_total']);
    }
}
