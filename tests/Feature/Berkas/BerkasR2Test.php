<?php

namespace Tests\Feature\Berkas;

use App\Support\BerkasPublik;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `POS_BERKAS=r2` mengarahkan disk `public` ke Cloudflare R2 lewat API S3
 * (dokumen 25 di repo Aishii). Konfigurasinya dibaca SAAT APLIKASI MENYALA,
 * jadi env-nya dipasang sebelum aplikasi dibuat dan dicabut sesudahnya —
 * uji lain yang berjalan sesudahnya tetap di disk lokal.
 */
class BerkasR2Test extends TestCase
{
    private const ENV = [
        'POS_BERKAS' => 'r2',
        'POS_R2_BUCKET' => 'aishii-pos-berkas',
        'POS_R2_ENDPOINT' => 'https://akun-contoh.r2.cloudflarestorage.com',
        'POS_R2_URL' => 'https://berkas-pos.contoh.id',
        'POS_R2_KUNCI_AKSES' => 'kunci-uji',
        'POS_R2_KUNCI_RAHASIA' => 'rahasia-uji',
    ];

    public function createApplication()
    {
        foreach (self::ENV as $nama => $nilai) {
            putenv("{$nama}={$nilai}");
            $_ENV[$nama] = $nilai;
            $_SERVER[$nama] = $nilai;
        }

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (array_keys(self::ENV) as $nama) {
            putenv($nama);
            unset($_ENV[$nama], $_SERVER[$nama]);
        }
    }

    public function test_disk_public_menunjuk_r2_lewat_api_s3(): void
    {
        $disk = config('filesystems.disks.public');

        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('auto', $disk['region']);
        $this->assertSame('https://akun-contoh.r2.cloudflarestorage.com', $disk['endpoint']);
        $this->assertTrue($disk['use_path_style_endpoint']);
        $this->assertTrue($disk['throw']);
        // R2 menolak ACL `public-read`; `private` diterima lalu diabaikan.
        // Yang mengubahnya menjadi `public` membuat SETIAP unggahan gagal.
        $this->assertSame('private', $disk['visibility']);
    }

    public function test_alamat_berkas_dibangun_dari_domain_publik_bucket(): void
    {
        $this->assertSame('https://berkas-pos.contoh.id/products/a.jpg', Storage::disk('public')->url('products/a.jpg'));
        $this->assertSame('https://berkas-pos.contoh.id/store/logo.png', BerkasPublik::url('store/logo.png'));
    }
}
