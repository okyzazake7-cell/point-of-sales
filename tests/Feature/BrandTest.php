<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Merek Aishii POS dan kebiasaan keluarga Aishii yang ikut bersamanya
 * (AS2/AS4, `docs/permintaan-3okt.md` di repo Aishii).
 *
 * Yang dijaga di sini yang TIDAK terlihat dari satu halaman pun: dua
 * kembaran merek (PHP dan React) yang bisa menyimpang diam-diam, manifest
 * PWA yang dibaca peramban saat memasang, bahasa yang ditebak dari HP, dan
 * cubit-zoom yang dicabut dari semua orang.
 */
class BrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_carries_aishii_pos_brand_and_not_the_old_one(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="theme-color" content="#752e8e">', $html);
        $this->assertStringContainsString('<meta property="og:site_name" content="Aishii POS">', $html);
        $this->assertStringContainsString('<title data-inertia>Aishii POS</title>', $html);
        $this->assertStringNotContainsString('Dikasir', $html);
        $this->assertStringNotContainsString('Point of Sales', $html);
        // Huruf disajikan dari bundel sendiri, bukan server Google.
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    public function test_brand_name_has_one_value_in_php_and_react(): void
    {
        $js = file_get_contents(resource_path('js/Utils/brand.js'));

        $this->assertSame('Aishii POS', config('brand.name'));
        $this->assertStringContainsString('name: "'.config('brand.name').'"', $js);
        $this->assertStringContainsString('themeColor: "'.config('brand.theme_color').'"', $js);
    }

    public function test_pwa_manifest_is_branded_and_every_icon_exists(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(config('brand.name'), $manifest['name']);
        $this->assertSame(config('brand.theme_color'), $manifest['theme_color']);
        $this->assertSame('id', $manifest['lang']);

        $icons = array_merge(
            $manifest['icons'],
            ...array_map(fn ($s) => $s['icons'] ?? [], $manifest['shortcuts'] ?? []),
        );
        $this->assertNotEmpty($icons);
        foreach ($icons as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')), "Ikon manifest {$icon['src']} tidak ada");
        }
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        // Ikon lama berukuran nol bita membuat tab peramban tanpa ikon.
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
    }

    public function test_interface_is_indonesian_even_when_the_phone_speaks_english(): void
    {
        $html = $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
            ->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="id">', $html);
    }

    public function test_an_explicit_english_choice_is_still_respected(): void
    {
        $html = $this->withCookie('locale', 'en')
            ->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="en">', $html);
    }

    public function test_pinch_zoom_is_only_disabled_for_the_installed_app(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        preg_match('/<meta name="viewport" content="([^"]+)">/', $html, $viewport);
        $this->assertNotEmpty($viewport);
        $this->assertStringNotContainsString('user-scalable', $viewport[1]);
        $this->assertStringNotContainsString('maximum-scale', $viewport[1]);
        // Dicabut hanya oleh skrip dini, dan hanya saat berdiri sendiri.
        $this->assertStringContainsString("m('(display-mode: standalone)').matches", $html);
    }
}
