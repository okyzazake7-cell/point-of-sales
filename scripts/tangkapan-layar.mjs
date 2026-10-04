/**
 * Membangkitkan ulang `public/screenshots/*.png` (1440×900) dari aplikasi
 * yang sedang berjalan dengan data demo.
 *
 * Kenapa ada: tangkapan layar dipajang di halaman depan, halaman Fitur, dan
 * README. Tangkapan yang dibuat sekali lalu dilupakan tertinggal dari
 * aplikasinya — sesudah tema Aishii, ke-33 gambar lama masih memajang
 * aplikasi berwarna nila bermerek lain. Skrip ini membuatnya bisa diulang
 * kapan saja tampilannya berubah.
 *
 * Pakai (server sudah berjalan):
 *   php artisan seed:demo --force && php artisan serve --port=8000
 *   node scripts/tangkapan-layar.mjs
 *   HANYA=01,02 node scripts/tangkapan-layar.mjs
 */
import puppeteer from 'puppeteer'
import { existsSync } from 'node:fs'

const BASE = process.env.BASE ?? 'http://127.0.0.1:8000'
const akar = new URL('..', import.meta.url).pathname
const HANYA = (process.env.HANYA ?? '').split(',').map(s => s.trim()).filter(Boolean)
const CHROMIUM = process.env.CHROMIUM
    ?? ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome']
        .find(p => existsSync(p))

/** Nama berkas → halaman. Urutan dan namanya mengikuti docs/screenshots.md. */
const HALAMAN = [
    ['01-dashboard', '/dashboard'],
    ['03-transaction-history', '/dashboard/transactions/history'],
    ['04-products', '/dashboard/products'],
    ['05-stock-mutations', '/dashboard/stock-mutations'],
    ['06-stock-opnames', '/dashboard/stock-opnames'],
    ['07-warehouses', '/dashboard/settings/warehouses'],
    ['08-stock-transfers', '/dashboard/stock-transfers'],
    ['09-purchase-orders', '/dashboard/purchase-orders'],
    ['10-goods-receivings', '/dashboard/goods-receivings'],
    ['11-supplier-returns', '/dashboard/supplier-returns'],
    ['12-receivables', '/dashboard/receivables'],
    ['13-payables', '/dashboard/payables'],
    ['14-aging', '/dashboard/aging'],
    ['15-sales-report', '/dashboard/reports/sales'],
    ['16-profit-report', '/dashboard/reports/profits'],
    ['17-insights', '/dashboard/reports/insights'],
    ['18-customers', '/dashboard/customers'],
    ['19-members', '/dashboard/members'],
    ['20-customer-segments', '/dashboard/customer-segments'],
    ['21-pricing-rules', '/dashboard/pricing-rules'],
    ['22-price-lists', '/dashboard/settings/price-lists'],
    ['23-store-profile', '/dashboard/settings/store'],
    ['24-payment-settings', '/dashboard/settings/payments'],
    ['25-bank-accounts', '/dashboard/settings/bank-accounts'],
    ['26-loyalty-settings', '/dashboard/settings/loyalty'],
    ['27-printer-settings', '/dashboard/settings/printer'],
    ['28-users', '/dashboard/users'],
    ['29-roles', '/dashboard/roles'],
    ['30-permissions', '/dashboard/permissions'],
    ['31-audit-logs', '/dashboard/audit-logs'],
    ['32-cashier-shifts', '/dashboard/cashier-shifts'],
    ['33-discount-approvals', '/dashboard/discount-approvals'],
]

const tunggu = (ms) => new Promise(r => setTimeout(r, ms))
const dipilih = (nama) => !HANYA.length || HANYA.some(h => nama.startsWith(h))

const browser = await puppeteer.launch({ executablePath: CHROMIUM, args: ['--no-sandbox', '--disable-dev-shm-usage'] })

async function masuk(surel) {
    const ctx = await browser.createBrowserContext()
    const page = await ctx.newPage()
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 })
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle2' })
    await page.type('input[name=email]', surel)
    await page.type('input[name=password]', 'password')
    await tunggu(2500) // penjaga bot menolak kiriman di bawah dua detik
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}), page.click('button[type=submit]')])
    // Tur pengenalan menutupi layar pada kunjungan pertama — ditandai selesai.
    await page.evaluate(() => Promise.all(['dashboard', 'pos', 'products', 'cashier_shifts', 'reports']
        .map(t => window.axios.post(`/dashboard/tours/${t}/complete`).catch(() => {}))))
    return { ctx, page }
}

let jumlah = 0
{
    const { ctx, page } = await masuk('arya@gmail.com')
    for (const [nama, jalan] of HALAMAN) {
        if (!dipilih(nama)) continue
        await page.goto(`${BASE}${jalan}`, { waitUntil: 'networkidle2', timeout: 30000 })
        await tunggu(700) // animasi grafik selesai
        await page.screenshot({ path: `${akar}public/screenshots/${nama}.png` })
        jumlah++
    }
    await ctx.close()
}

// Layar kasir dipotret sebagai KASIR dengan shift terbuka dan keranjang
// berisi — layar kasir kosong tidak menunjukkan apa pun tentang kasirnya.
if (dipilih('02-pos-checkout')) {
    const { ctx, page } = await masuk('cashier@gmail.com')
    await page.goto(`${BASE}/dashboard/transactions`, { waitUntil: 'networkidle2' })
    // Tombol pertama tiap kartu produk = gambar produk yang menambah satuan dasar.
    const tombol = await page.$$('.grid > div > button:first-of-type:not([disabled])')
    for (const t of tombol.slice(0, 3)) {
        await t.click().catch(() => {})
        await tunggu(900)
    }
    // Notifikasi "ditambahkan" hilang sendiri sesudah 3 detik, dan tetikus
    // dipindah supaya kartu terakhir tidak terpotret dalam keadaan disorot.
    await page.mouse.move(5, 890)
    await tunggu(3800)
    await page.screenshot({ path: `${akar}public/screenshots/02-pos-checkout.png` })
    jumlah++
    await ctx.close()
}

await browser.close()
console.log(`${jumlah} tangkapan layar ditulis ke public/screenshots/.`)
