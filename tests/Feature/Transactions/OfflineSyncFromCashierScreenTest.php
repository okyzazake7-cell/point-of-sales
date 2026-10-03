<?php

namespace Tests\Feature\Transactions;

use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Antrean penjualan luring dari LAYAR KASIR (AS12, `docs/permintaan-3okt.md`
 * di repo Aishii).
 *
 * Layar kasir mengirim antreannya dengan SESI peramban, tanpa token. Jalur
 * API `/api/v1/pos/transactions/sync` berpagar `auth:sanctum` tanpa sesi —
 * terukur 3 Okt: tiap kiriman dari peramban dijawab 401, penjualan luring
 * ditandai gagal di perangkat dan dipangkas sesudah 30 hari. Uji API yang
 * sudah ada lulus karena memakai Sanctum::actingAs — token tiruan yang
 * tidak pernah dimiliki layar kasir.
 *
 * 401 itu sendiri TIDAK bisa diulang di sini: actingAs menaruh pengguna di
 * penjaga `web` yang juga dibaca Sanctum, sehingga jalur API terlihat
 * terbuka bagi "sesi" uji. Buktinya peramban sungguhan —
 * `scripts/uji-luring-per-toko.mjs` mengirim antrean dari layar kasir dan
 * memeriksa transaksinya benar-benar lahir.
 */
class OfflineSyncFromCashierScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->kasir = User::factory()->create();
        $this->kasir->assignRole('cashier');
        $gudang = Warehouse::create(['code' => 'WH-LURING', 'name' => 'Gudang Luring', 'status' => 'active']);
        $kategori = Category::create(['name' => 'Luring', 'image' => '', 'description' => '']);
        $this->produk = Product::create([
            'title' => 'Teh Luring', 'barcode' => 'LURING-1', 'sku' => 'SKU-LURING-1', 'image' => '',
            'description' => '', 'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 20,
            'category_id' => $kategori->id, 'tax_type' => 'exclusive', 'tax_rate' => 0,
            'min_stock' => 0, 'max_stock' => 100, 'is_composite' => false,
        ]);
        $this->produk->warehouses()->attach($gudang->id, ['stock' => 20]);
        app(CashierShiftService::class)->openShift(
            cashier: $this->kasir, actor: $this->kasir, openingCash: 0, notes: null, warehouseId: $gudang->id,
        );
    }

    private function antrean(): array
    {
        return ['transactions' => [[
            'client_uuid' => '6f1d2c3b-4a5e-4f60-8a7b-9c0d1e2f3a4b',
            'items' => [['product_id' => $this->produk->id, 'qty' => 2, 'unit_id' => null]],
            'cash' => 20000,
            'recorded_at' => now()->subMinutes(5)->toIso8601String(),
        ]]];
    }

    public function test_the_cashier_screen_syncs_its_offline_queue_with_its_session(): void
    {
        $this->actingAs($this->kasir)
            ->postJson(route('transactions.sync-offline'), $this->antrean())
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'synced');

        $this->assertSame(1, Transaction::query()->where('client_uuid', '6f1d2c3b-4a5e-4f60-8a7b-9c0d1e2f3a4b')->count());
    }

    public function test_the_session_route_still_needs_the_cashier_permission(): void
    {
        $tanpaIzin = User::factory()->create();

        $this->actingAs($tanpaIzin)
            ->postJson(route('transactions.sync-offline'), $this->antrean())
            ->assertForbidden();
    }
}
