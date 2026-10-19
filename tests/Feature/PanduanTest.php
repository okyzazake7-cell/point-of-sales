<?php

namespace Tests\Feature;

use App\Http\Controllers\TourController;
use Tests\TestCase;

/**
 * Panduan per halaman (resources/js/Panduan). Pemilik meminta panduan yang
 * rinci di SELURUH halaman, untuk orang yang awam teknologi — dan tombol
 * "?" yang dulu hanya bekerja di Dashboard membuktikan bahwa janji itu
 * mudah lepas tanpa terlihat. Yang dijaga di sini:
 *
 *   - setiap halaman toko (dan halaman tamu yang dibuka orang awam) punya
 *     panduan — halaman baru tanpa panduan memerahkan uji ini;
 *   - setiap langkah punya teks Indonesia dan Inggris, tanpa teks yatim;
 *   - setiap elemen yang ditunjuk benar-benar ditandai `data-tour` di kode
 *     React (salah ketik nama membuat langkah itu diam-diam dilewati);
 *   - isi panduan hanya memakai tag sederhana (dirender sebagai HTML).
 *
 * Bahwa langkahnya menemukan elemen yang BENAR di peramban sungguhan
 * diukur `scripts/audit-panduan.mjs`.
 */
class PanduanTest extends TestCase
{
    /** Halaman tamu yang ikut berpanduan: pintu masuk orang awam. */
    private const HALAMAN_TAMU = ['Auth/Login', 'Penyewaan/Daftar', 'Public/DineMenu'];

    private const TAG_BOLEH = ['b', 'i', 'br', 'ul', 'ol', 'li', 'kbd'];

    private function daftar(): array
    {
        return json_decode(file_get_contents(resource_path('js/Panduan/daftar.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function teks(string $bahasa): array
    {
        return json_decode(file_get_contents(resource_path("js/Panduan/teks/{$bahasa}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, list<array>> komponen → varian panduan (rujukan sudah diikuti) */
    private function panduan(): array
    {
        $daftar = $this->daftar();
        $hasil = [];
        foreach ($daftar as $komponen => $entri) {
            $hasil[$komponen] = is_string($entri) ? ($daftar[$entri] ?? null) : $entri;
        }

        return $hasil;
    }

    /** @return list<string> semua nama komponen yang dirender Inertia di sisi server */
    private function komponenInertia(): array
    {
        $nama = [];
        foreach ([app_path(), base_path('routes'), base_path('bootstrap')] as $akar) {
            $berkas = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($akar, \FilesystemIterator::SKIP_DOTS));
            foreach ($berkas as $b) {
                if ($b->getExtension() !== 'php') {
                    continue;
                }
                preg_match_all("/Inertia::render\\(\\s*'([^']+)'/", file_get_contents($b->getPathname()), $cocok);
                array_push($nama, ...$cocok[1]);
            }
        }

        return array_values(array_unique($nama));
    }

    /** @return array<string, true> nama data-tour yang tertulis di kode React */
    private function anchorDiKode(): array
    {
        $nama = [];
        $berkas = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS));
        foreach ($berkas as $b) {
            if (! in_array($b->getExtension(), ['js', 'jsx'], true)) {
                continue;
            }
            $isi = file_get_contents($b->getPathname());
            // data-tour="x", data-tour={kondisi ? "x" : undefined}, dan prop
            // `tour="x"` yang diteruskan komponen di berkas yang sama.
            preg_match_all('/(?:data-tour|\btour)=("[^"]*"|\{[^}]*\})/', $isi, $cocok);
            foreach ($cocok[1] as $nilai) {
                preg_match_all('/"([a-z0-9][a-z0-9-]*)"/', $nilai, $isiNilai);
                foreach ($isiNilai[1] as $n) {
                    $nama[$n] = true;
                }
            }
        }

        return $nama;
    }

    public function test_every_store_page_and_guest_door_has_a_guide(): void
    {
        $wajib = array_filter(
            $this->komponenInertia(),
            fn ($k) => str_starts_with($k, 'Dashboard/') || $k === 'Profile/Edit' || in_array($k, self::HALAMAN_TAMU, true),
        );
        $panduan = $this->panduan();

        $tanpa = array_values(array_filter($wajib, fn ($k) => empty($panduan[$k])));

        $this->assertNotEmpty($wajib);
        $this->assertSame([], $tanpa, 'Halaman tanpa panduan: '.implode(', ', $tanpa));
    }

    public function test_every_step_has_indonesian_and_english_text_and_no_orphans(): void
    {
        $id = $this->teks('id');
        $en = $this->teks('en');
        $langkahPerKunci = [];

        foreach ($this->daftar() as $komponen => $entri) {
            if (is_string($entri)) {
                $this->assertIsArray($this->daftar()[$entri] ?? null, "{$komponen} merujuk {$entri} yang tidak ada");

                continue;
            }
            foreach ($entri as $varian) {
                $kunci = $varian['key'];
                $this->assertArrayNotHasKey($kunci, $langkahPerKunci, "Kunci {$kunci} dipakai dua panduan — Tambah/Ubah memakai rujukan");
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{1,47}$/', $kunci);
                $this->assertTrue(
                    collect($varian['steps'])->contains(fn ($s) => ! isset($s['el'])),
                    "{$kunci}: tidak ada langkah pembuka tanpa elemen",
                );
                $langkahPerKunci[$kunci] = array_column($varian['steps'], 'id');

                foreach ($varian['steps'] as $langkah) {
                    foreach (['id' => $id, 'en' => $en] as $bahasa => $teks) {
                        $t = $teks[$kunci][$langkah['id']] ?? null;
                        $this->assertNotEmpty($t['title'] ?? null, "{$kunci}.{$langkah['id']}: judul {$bahasa} kosong");
                        $this->assertNotEmpty($t['desc'] ?? null, "{$kunci}.{$langkah['id']}: isi {$bahasa} kosong");
                    }
                }
            }
        }

        foreach (['id' => $id, 'en' => $en] as $bahasa => $teks) {
            foreach ($teks as $kunci => $langkah) {
                $this->assertArrayHasKey($kunci, $langkahPerKunci, "Teks {$bahasa} {$kunci} tanpa panduan");
                $yatim = array_diff(array_keys($langkah), $langkahPerKunci[$kunci]);
                $this->assertSame([], array_values($yatim), "Teks {$bahasa} {$kunci} tanpa langkah");
            }
        }

        // Kunci yang boleh ditandai selesai = kunci di daftar ini juga.
        $this->assertEqualsCanonicalizing(array_keys($langkahPerKunci), TourController::tours());
    }

    public function test_every_pointed_element_is_marked_in_the_interface_code(): void
    {
        $ada = $this->anchorDiKode();
        $hilang = [];

        foreach ($this->daftar() as $komponen => $entri) {
            foreach (is_array($entri) ? $entri : [] as $varian) {
                $nama = array_filter([$varian['if'] ?? null, ...array_column($varian['steps'], 'el')]);
                foreach ($nama as $n) {
                    if (! isset($ada[$n])) {
                        $hilang[] = "{$varian['key']} → {$n}";
                    }
                }
            }
        }

        $this->assertSame([], $hilang, 'data-tour tidak ditemukan di resources/js: '.implode(', ', $hilang));
    }

    public function test_guide_texts_use_only_simple_markup(): void
    {
        foreach (['id', 'en'] as $bahasa) {
            foreach ($this->teks($bahasa) as $kunci => $langkah) {
                foreach ($langkah as $idLangkah => $t) {
                    foreach ([$t['title'], $t['desc']] as $isi) {
                        preg_match_all('/<\s*\/?\s*([a-zA-Z0-9]+)([^>]*)>/', $isi, $tag, PREG_SET_ORDER);
                        foreach ($tag as [$utuh, $nama, $atribut]) {
                            $this->assertContains(strtolower($nama), self::TAG_BOLEH, "{$bahasa} {$kunci}.{$idLangkah}: {$utuh}");
                            $this->assertContains(trim($atribut), ['', '/'], "{$bahasa} {$kunci}.{$idLangkah}: atribut di {$utuh}");
                        }
                        $this->assertDoesNotMatchRegularExpression('/<[^>]*$/', $isi, "{$bahasa} {$kunci}.{$idLangkah}: tag terpotong");
                    }
                }
            }
        }
    }
}
