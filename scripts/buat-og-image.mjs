/**
 * Membangkitkan `public/images/og-image.jpg` (1200×630) — gambar pratinjau
 * yang muncul saat tautan Aishii POS dibagikan ke WhatsApp atau media sosial.
 *
 * JPEG, BUKAN PNG: gradiennya membuat PNG membengkak ±430 KB, dan WhatsApp
 * kerap tidak menampilkan pratinjau bergambar di atas ±300 KB — padahal
 * WhatsApp-lah tempat tautan ini paling sering dibagikan. `BrandTest`
 * menjaga batasnya.
 *
 * Skrip inilah SUMBERNYA, bukan berkas SVG yang disunting tangan: huruf
 * Plus Jakarta Sans dan ikon Aishii disematkan dari berkas yang memang
 * dipakai aplikasi, jadi pratinjaunya tidak bisa menyimpang dari merek di
 * `config/brand.php`. Mengganti kalimatnya = menyunting `TEKS` lalu
 * menjalankan ulang.
 *
 * Pakai: node scripts/buat-og-image.mjs
 */
import puppeteer from 'puppeteer'
import { existsSync, readFileSync } from 'node:fs'

const akar = new URL('..', import.meta.url).pathname
const CHROMIUM = process.env.CHROMIUM
    ?? ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome']
        .find(p => existsSync(p))

const TEKS = {
    nama: 'Aishii POS',
    tagline: 'Kasir toko dari keluarga Aishii',
    fitur: 'Barcode · Stok per gudang · Pemasok & retur · Piutang · Member & poin',
    atribusi: 'Berbasis Point of Sales karya Arya Dwi Putra (MIT)',
}

const b64 = (jalan) => readFileSync(`${akar}${jalan}`).toString('base64')
const huruf = b64('node_modules/@fontsource-variable/plus-jakarta-sans/files/plus-jakarta-sans-latin-wght-normal.woff2')
const ikon = b64('public/images/icon-512.png')

const html = `<!doctype html><html><head><meta charset="utf-8"><style>
@font-face { font-family: 'PJS'; font-weight: 200 800; src: url(data:font/woff2;base64,${huruf}) format('woff2'); }
* { margin: 0; box-sizing: border-box; }
body { width: 1200px; height: 630px; overflow: hidden; font-family: 'PJS', sans-serif;
  background: radial-gradient(circle at 85% 15%, rgba(255,255,255,.12), transparent 40%),
              linear-gradient(135deg, #752e8e 0%, #451c53 60%, #2b0e36 100%); color: #fff; }
.isi { position: absolute; left: 96px; top: 118px; right: 96px; }
.baris { display: flex; align-items: center; gap: 40px; }
img { width: 168px; height: 168px; border-radius: 36px; box-shadow: 0 0 0 6px rgba(255,255,255,.25); }
h1 { font-size: 104px; font-weight: 800; letter-spacing: -2px; line-height: 1; }
.tag { margin-top: 18px; font-size: 40px; font-weight: 600; color: #f0e6f6; }
.fitur { margin-top: 64px; font-size: 30px; font-weight: 500; color: #e2cdee; }
.atribusi { position: absolute; left: 96px; bottom: 44px; font-size: 22px; color: #cba7df; }
</style></head><body>
<div class="isi">
  <div class="baris"><img src="data:image/png;base64,${ikon}" alt="">
    <div><h1>${TEKS.nama}</h1><p class="tag">${TEKS.tagline}</p></div></div>
  <p class="fitur">${TEKS.fitur}</p>
</div>
<p class="atribusi">${TEKS.atribusi}</p>
</body></html>`

const browser = await puppeteer.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] })
const page = await browser.newPage()
await page.setViewport({ width: 1200, height: 630, deviceScaleFactor: 1 })
await page.setContent(html, { waitUntil: 'load' })
await page.evaluate(() => document.fonts.ready)
await page.screenshot({ path: `${akar}public/images/og-image.jpg`, type: 'jpeg', quality: 88 })
await browser.close()
console.log('public/images/og-image.jpg ditulis (1200×630).')
