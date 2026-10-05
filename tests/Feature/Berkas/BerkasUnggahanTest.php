<?php

namespace Tests\Feature\Berkas;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\BerkasPublik;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Unggahan POS lewat SATU disk `public` (dokumen 25 di repo Aishii).
 *
 * Di Cloud Run disk `public` adalah Cloudflare R2. Berkas yang mendarat di
 * disk lain — `local`, tempat hulu menaruh gambar produk dan kategori —
 * hilang tiap instans bangun ulang tanpa satu galat pun, dan alamat yang
 * diketik `asset('storage/…')` menunjuk berkas yang tidak pernah ada di sana.
 */
class BerkasUnggahanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $kategori;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();
        $this->actingAs($this->admin);

        $this->kategori = Category::create(['name' => 'Kategori Uji', 'description' => 'Uji']);
        Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_gambar_produk_mendarat_di_disk_public_dan_alamatnya_dari_disk_itu(): void
    {
        $this->post(route('products.store'), $this->produk())->assertRedirect(route('products.index'));

        $produk = Product::latest('id')->first();
        $nama = $produk->getRawOriginal('image');

        Storage::disk('public')->assertExists('products/'.$nama);
        Storage::disk('local')->assertMissing('public/products/'.$nama);
        $this->assertSame(Storage::disk('public')->url('products/'.$nama), $produk->image);
    }

    public function test_menghapus_produk_ikut_menghapus_gambarnya(): void
    {
        $this->post(route('products.store'), $this->produk());
        $produk = Product::latest('id')->first();
        $nama = $produk->getRawOriginal('image');
        Storage::disk('public')->assertExists('products/'.$nama);

        $this->delete(route('products.destroy', $produk->id))->assertRedirect();

        Storage::disk('public')->assertMissing('products/'.$nama);
    }

    public function test_gambar_kategori_mendarat_di_disk_public(): void
    {
        $this->post(route('categories.store'), [
            'name' => 'Minuman',
            'image' => UploadedFile::fake()->image('kategori.png'),
        ])->assertRedirect();

        $kategori = Category::where('name', 'Minuman')->first();
        $nama = $kategori->getRawOriginal('image');

        Storage::disk('public')->assertExists('category/'.$nama);
        $this->assertSame(Storage::disk('public')->url('category/'.$nama), $kategori->image);
    }

    public function test_logo_toko_tersimpan_di_disk_public_dan_layar_menerima_alamatnya(): void
    {
        $this->post(route('settings.store.update'), [
            'store_name' => 'Toko Uji',
            'store_address' => 'Jl. Uji',
            'store_logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertSessionHasNoErrors();

        $jalur = Setting::getForOutlet('store_logo', null);
        $this->assertStringStartsWith('store/', $jalur);
        Storage::disk('public')->assertExists($jalur);

        $this->get(route('settings.store'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.store_logo', Storage::disk('public')->url($jalur)));
    }

    public function test_logo_untuk_pdf_dibaca_dari_disk_dengan_jenis_dari_isinya(): void
    {
        Storage::disk('public')->put('store/logo.jpg', UploadedFile::fake()->image('logo.jpg')->getContent());
        Storage::disk('public')->put('store/bukan-gambar.png', 'halo');

        $this->assertStringStartsWith('data:image/jpeg;base64,', BerkasPublik::dataUri('store/logo.jpg'));
        // Nilai lama berawalan /storage/ dibaca dari disk yang sama.
        $this->assertStringStartsWith('data:image/jpeg;base64,', BerkasPublik::dataUri('/storage/store/logo.jpg'));
        $this->assertNull(BerkasPublik::dataUri('store/tidak-ada.png'));
        $this->assertNull(BerkasPublik::dataUri('store/bukan-gambar.png'));
        // Alamat jaringan TIDAK diambil: dompdf yang mengambil sendiri adalah pintu SSRF.
        $this->assertNull(BerkasPublik::dataUri('https://contoh.id/logo.png'));
    }

    public function test_alamat_yang_sudah_penuh_dibiarkan_dan_kosong_tetap_kosong(): void
    {
        $this->assertSame('https://contoh.id/a.png', BerkasPublik::url('https://contoh.id/a.png'));
        $this->assertSame('/storage/products/a.png', BerkasPublik::url('/storage/products/a.png'));
        $this->assertNull(BerkasPublik::url(null));
        $this->assertNull(BerkasPublik::url(''));
    }

    private function produk(): array
    {
        return [
            'image' => UploadedFile::fake()->image('produk.png'),
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Uji',
            'description' => 'Deskripsi uji.',
            'category_id' => $this->kategori->id,
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 10,
            'tax_rate' => 0,
        ];
    }
}
