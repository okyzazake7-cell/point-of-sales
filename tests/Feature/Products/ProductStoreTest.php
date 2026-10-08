<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\StockMutation;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();
        $this->actingAs($this->admin);

        $this->category = Category::create([
            'name' => 'Kategori Test',
            'image' => 'categories/test.jpg',
            'description' => 'Kategori untuk test',
        ]);

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'image' => UploadedFile::fake()->image('product.png'),
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Uji',
            'description' => 'Deskripsi uji.',
            'category_id' => $this->category->id,
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 100,
            'tax_rate' => 0,
        ], $overrides);
    }

    public function test_store_creates_pivot_row_in_default_warehouse(): void
    {
        $this->post(route('products.store'), $this->validPayload())
            ->assertRedirect(route('products.index'));

        $product = Product::latest('id')->first();

        $pivot = ProductWarehouse::where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($pivot);
        $this->assertEquals(100, $pivot->stock);
        $this->assertEquals(100, $product->fresh()->stock);
    }

    public function test_store_records_initial_mutation_with_warehouse(): void
    {
        $this->post(route('products.store'), $this->validPayload());

        $product = Product::latest('id')->first();

        $mutation = StockMutation::where('reference_type', 'product_create')
            ->where('product_id', $product->id)
            ->first();
        $this->assertNotNull($mutation);
        $this->assertEquals($this->warehouse->id, $mutation->warehouse_id);
        $this->assertEquals(100, $mutation->qty);
        $this->assertEquals(0, $mutation->stock_before);
        $this->assertEquals(100, $mutation->stock_after);
    }

    /**
     * A store with ONE selling outlet (what Aishii POS sign-up creates: a
     * non-selling PUSAT plus "Toko Utama") sells only from that outlet's
     * warehouse — the cashier searches the shift's warehouse. Initial stock
     * left in PUSAT made every new product invisible at the cashier.
     */
    public function test_store_puts_initial_stock_in_the_only_selling_outlets_warehouse(): void
    {
        $utama = $this->sellingOutletWithWarehouse('UTAMA');

        $this->post(route('products.store'), $this->validPayload())
            ->assertRedirect(route('products.index'));

        $product = Product::latest('id')->first();
        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id, 'warehouse_id' => $utama->id, 'stock' => 100,
        ]);
        $this->assertDatabaseMissing('product_warehouse', [
            'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id,
        ]);
        $this->assertSame($utama->id, StockMutation::where('reference_type', 'product_create')
            ->where('product_id', $product->id)->value('warehouse_id'));
    }

    public function test_store_keeps_the_main_warehouse_when_several_outlets_sell(): void
    {
        $this->sellingOutletWithWarehouse('MAL');
        $this->sellingOutletWithWarehouse('TKB');

        $this->post(route('products.store'), $this->validPayload())
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => Product::latest('id')->value('id'), 'warehouse_id' => $this->warehouse->id, 'stock' => 100,
        ]);
    }

    private function sellingOutletWithWarehouse(string $code): Warehouse
    {
        $outlet = Outlet::create([
            'code' => $code,
            'name' => "Outlet {$code}",
            'is_active' => true,
            'is_sales_enabled' => true,
        ]);

        return Warehouse::create([
            'outlet_id' => $outlet->id,
            'code' => $code,
            'name' => "Gudang {$code}",
            'type' => 'branch',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_store_uses_requested_warehouse(): void
    {
        $other = Warehouse::create([
            'code' => 'WH-2',
            'name' => 'Gudang Kedua',
            'type' => 'branch',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->post(route('products.store'), $this->validPayload(['warehouse_id' => $other->id]))
            ->assertRedirect(route('products.index'));

        $product = Product::latest('id')->first();

        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $product->id,
            'warehouse_id' => $other->id,
            'stock' => 100,
        ]);
    }

    public function test_update_persists_min_and_max_stock(): void
    {
        $product = Product::create([
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Uji',
            'description' => 'Deskripsi uji.',
            'category_id' => $this->category->id,
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 100,
            'tax_rate' => 0,
        ]);

        $payload = [
            'image' => UploadedFile::fake()->image('product.png'),
            'barcode' => $product->barcode,
            'sku' => $product->sku,
            'title' => 'Produk Uji',
            'description' => 'Deskripsi uji.',
            'category_id' => $this->category->id,
            'buy_price' => 5000,
            'sell_price' => 10000,
            'tax_rate' => 0,
            'min_stock' => 25,
            'max_stock' => 250,
        ];

        $this->put(route('products.update', $product), $payload)
            ->assertRedirect(route('products.index'));

        $product = $product->fresh();
        $this->assertEquals(25, $product->min_stock);
        $this->assertEquals(250, $product->max_stock);
    }

    public function test_store_allows_missing_image_and_description(): void
    {
        $payload = $this->validPayload();
        unset($payload['image']);
        unset($payload['description']);

        $this->post(route('products.store'), $payload)
            ->assertRedirect(route('products.index'));

        $product = Product::latest('id')->first();

        $this->assertNull($product->image);
        $this->assertNull($product->description);
    }
}
