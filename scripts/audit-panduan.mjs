/**
 * AUDIT PANDUAN — membuktikan bahwa SETIAP halaman punya panduan yang
 * benar-benar berjalan di peramban sungguhan, di layar lebar dan ponsel.
 *
 * Kenapa ada: pemilik meminta panduan yang rinci di seluruh halaman untuk
 * orang yang awam teknologi. Dulu tombol "?" hanya bekerja di Dashboard dan
 * tidak ada yang tahu — dari kodenya semua tampak terpasang. Yang
 * membuktikannya cuma peramban.
 *
 * Yang diperiksa per halaman, per lebar:
 *   - komponen Inertia halaman itu terdaftar di Panduan/daftar.json;
 *   - tombol panduan ("?" di bilah atas, menu akun/☰ di ponsel, atau tombol
 *     "Panduan" di halaman tanpa bilah atas) ada dan membuka panduannya;
 *   - setiap langkah yang tampil memakai teks panduan itu, menyorot elemen
 *     `data-tour` yang dimaksud langkahnya, dan kotaknya utuh di layar;
 *   - tidak ada galat JavaScript.
 * Langkah yang elemennya tidak terlihat dalam keadaan data saat itu
 * (daftar kosong, lebar layar) memang dilewati panduannya — dicatat, bukan
 * dianggap gagal.
 *
 * Pakai (server Laravel berjalan, data demo terpasang, aset dibangun atau
 * server Vite menyala):
 *   php artisan seed:demo --force && php artisan serve --port=8000
 *   node scripts/audit-panduan.mjs                 # semua halaman, 1366 + 390
 *   HANYA=products,dashboard node scripts/audit-panduan.mjs
 *   LEBAR=390 TANGKAPAN=/tmp/panduan node scripts/audit-panduan.mjs
 *
 * Mode banyak toko (BANYAK_TOKO=1, server ber-POS_MULTI_TOKO=true): hanya
 * layar yang tidak ada di mode satu toko — Daftar Toko dan Langganan —
 * ditambah halaman masuk. Dengan "Masuk dengan akun Aishii" menyala
 * (AISHII_OIDC_ID_KLIEN/AISHII_OIDC_RAHASIA_KLIEN terisi) /login dan
 * /daftar menampilkan varian akun Aishii-nya; tanpa itu, formulir biasa.
 *   BANYAK_TOKO=1 BASE=http://127.0.0.1:8001 SUREL=pemilik@toko SANDI=… node scripts/audit-panduan.mjs
 *
 * Keluar 1 bila ada satu kegagalan pun.
 */
import puppeteer from 'puppeteer'
import { existsSync, mkdirSync, readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'

const BASE = process.env.BASE ?? 'http://127.0.0.1:8000'
const LEBAR = (process.env.LEBAR ?? '1366,390').split(',').map(Number)
const TANGKAPAN = process.env.TANGKAPAN ?? ''
const HANYA = (process.env.HANYA ?? '').split(',').map(s => s.trim()).filter(Boolean)
const BANYAK_TOKO = process.env.BANYAK_TOKO === '1'
const CHROMIUM = process.env.CHROMIUM
    ?? ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome']
        .find(p => existsSync(p))

const baca = (rel) => JSON.parse(readFileSync(new URL(`../resources/js/Panduan/${rel}`, import.meta.url), 'utf8'))
const DAFTAR = baca('daftar.json')
const TEKS = { id: baca('teks/id.json'), en: baca('teks/en.json') }

const DASHBOARD = [
    '/dashboard', '/dashboard/access', '/dashboard/transactions', '/dashboard/transactions/history',
    '/dashboard/products', '/dashboard/products/create', '/dashboard/categories', '/dashboard/categories/create',
    '/dashboard/customers', '/dashboard/customers/create', '/dashboard/suppliers', '/dashboard/members',
    '/dashboard/members/create', '/dashboard/pricing-rules', '/dashboard/pricing-rules/create',
    '/dashboard/customer-vouchers', '/dashboard/customer-vouchers/create', '/dashboard/customer-segments',
    '/dashboard/customer-segments/create', '/dashboard/crm-campaigns', '/dashboard/crm-campaigns/create',
    '/dashboard/crm-reminders', '/dashboard/purchase-orders', '/dashboard/purchase-orders/create',
    '/dashboard/goods-receivings', '/dashboard/goods-receivings/create', '/dashboard/supplier-returns',
    '/dashboard/supplier-returns/create', '/dashboard/payables', '/dashboard/receivables', '/dashboard/aging',
    '/dashboard/stock-mutations', '/dashboard/stock-opnames', '/dashboard/stock-opnames/create',
    '/dashboard/stock-transfers', '/dashboard/stock-transfers/create', '/dashboard/sales-returns',
    '/dashboard/cashier-shifts', '/dashboard/discount-approvals', '/dashboard/dine-areas',
    '/dashboard/dine-tables', '/dashboard/dine-orders', '/dashboard/reports/sales', '/dashboard/reports/profits',
    '/dashboard/reports/insights', '/dashboard/users', '/dashboard/users/create', '/dashboard/roles',
    '/dashboard/permissions', '/dashboard/audit-logs', '/dashboard/profile', '/dashboard/settings/store',
    '/dashboard/settings/payments', '/dashboard/settings/bank-accounts', '/dashboard/settings/bank-accounts/create',
    '/dashboard/settings/loyalty', '/dashboard/settings/outlets', '/dashboard/settings/price-lists',
    '/dashboard/settings/printer', '/dashboard/settings/target', '/dashboard/settings/units',
    '/dashboard/settings/warehouses', '/dashboard/settings/whatsapp',
]

/** Halaman rincian/ubah: alamatnya dibaca dari tautan di halaman daftarnya. */
const RINCIAN_DARI = [
    '/dashboard/products', '/dashboard/categories', '/dashboard/customers', '/dashboard/members',
    '/dashboard/pricing-rules', '/dashboard/customer-vouchers', '/dashboard/customer-segments',
    '/dashboard/crm-campaigns', '/dashboard/purchase-orders', '/dashboard/goods-receivings',
    '/dashboard/supplier-returns', '/dashboard/payables', '/dashboard/receivables',
    '/dashboard/stock-opnames', '/dashboard/stock-transfers', '/dashboard/sales-returns',
    '/dashboard/cashier-shifts', '/dashboard/audit-logs', '/dashboard/users',
    '/dashboard/settings/bank-accounts', '/dashboard/settings/price-lists',
]

/**
 * Rincian yang tampilannya berubah menurut status (draf/final, dikirim,
 * selesai): beberapa rincian dibuka, supaya setiap varian panduan dan
 * tombolnya ikut teruji — bukan hanya rincian pertama di daftar.
 */
const BEBERAPA_KEADAAN = new Set([
    '/dashboard/stock-opnames', '/dashboard/sales-returns', '/dashboard/purchase-orders',
    '/dashboard/stock-transfers',
])

const tunggu = (ms) => new Promise(r => setTimeout(r, ms))

const browser = await puppeteer.launch({
    executablePath: CHROMIUM,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
})

async function masuk(page, surel) {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle2' })
    // Panduan halaman masuk muncul sendiri ±0,7 detik sesudah halaman siap
    // dan lapisannya menelan klik tombol Masuk — tutup dulu.
    await tunggu(1200)
    await tutupPanduan(page)
    await page.type('input[name=email]', surel)
    await page.type('input[name=password]', process.env.SANDI ?? 'password')
    // Penjaga bot menolak kiriman yang lebih cepat dari dua detik.
    await tunggu(2500)
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
        page.click('button[type=submit]'),
    ])
    if (/\/login$/.test(new URL(page.url()).pathname)) throw new Error(`gagal masuk sebagai ${surel}`)
    // Mulai dari nol: setiap panduan boleh muncul sendiri lagi.
    await page.evaluate(() => window.axios.post('/dashboard/tours/reset').catch(() => {}))
}

async function tutupPanduan(page) {
    for (let i = 0; i < 3 && await page.$('.driver-popover'); i++) {
        await page.keyboard.press('Escape')
        await tunggu(300)
    }
}

/** Panduan yang berlaku untuk keadaan layar sekarang — logika yang sama dengan aplikasi. */
async function panduanHalaman(page) {
    return page.evaluate((daftar) => {
        const data = document.querySelector('script[data-page="app"]')?.textContent
        const komponen = data ? JSON.parse(data).component : null
        let entri = daftar[komponen]
        if (typeof entri === 'string') entri = daftar[entri]
        const terlihat = (nama) => [...document.querySelectorAll(`[data-tour="${nama}"]`)].some((el) => {
            const r = el.getBoundingClientRect()
            return el.getClientRects().length > 0 && r.width > 0 && r.height > 0
                && r.right > 0 && r.left < innerWidth && getComputedStyle(el).visibility !== 'hidden'
        })
        const varian = Array.isArray(entri) ? entri.find((g) => !g.if || terlihat(g.if)) : null
        return { komponen, varian, terlihat: varian ? varian.steps.filter((s) => s.el && terlihat(s.el)).map((s) => s.id) : [] }
    }, DAFTAR)
}

async function bukaPanduan(page) {
    if (await page.$('.driver-popover')) return true
    const klik = async (pemilih) => {
        const el = await page.evaluateHandle((p) => [...document.querySelectorAll(p)].find((e) => e.getClientRects().length > 0) ?? null, pemilih)
        if (!(await el.evaluate((e) => !!e))) return false
        await el.click()
        await tunggu(400)
        return true
    }
    if (await klik('[data-tour="nav-help"], [data-tour="pos-help"], [data-tour="page-help"]')) {
        // ok
    }
    // Ponsel: tombol panduan ada di menu ☰ (kasir) atau menu akun (dashboard).
    else if (await klik('[data-tour="pos-menu"]') || await klik('[data-tour="nav-account"] button')) {
        await page.evaluate(() => [...document.querySelectorAll('button')]
            .find((b) => b.getClientRects().length > 0 && /Panduan Penggunaan|Guided Tour/.test(b.innerText))?.click())
    }
    try {
        await page.waitForSelector('.driver-popover', { timeout: 6000 })
        return true
    }
    catch {
        return false
    }
}

let jumlahGagal = 0
const baris = []

async function auditHalaman(page, jalan, lebar, galatJs) {
    galatJs.length = 0
    let status = 0
    try {
        const res = await page.goto(`${BASE}${jalan}`, { waitUntil: 'networkidle2', timeout: 30000 })
        status = res?.status() ?? 0
    }
    catch (e) {
        jumlahGagal++
        baris.push(`GAGAL ${lebar}px ${jalan}: tidak terbuka ${String(e).slice(0, 80)}`)
        return
    }
    await tunggu(900)
    const { komponen, varian, terlihat } = await panduanHalaman(page)
    if (!varian) {
        jumlahGagal++
        baris.push(`GAGAL ${lebar}px ${jalan} [${status}] ${komponen}: tidak ada panduan`)
        return
    }
    if (!(await bukaPanduan(page))) {
        jumlahGagal++
        baris.push(`GAGAL ${lebar}px ${jalan} ${komponen} [${varian.key}]: panduan tidak terbuka`)
        return
    }

    const teks = TEKS.id[varian.key] ?? {}
    const judulKeLangkah = new Map(varian.steps.map((s) => [teks[s.id]?.title, s]))
    const tampil = []
    const masalah = []
    for (let i = 0; i < 40; i++) {
        await tunggu(700)
        const info = await page.evaluate(() => {
            const pop = document.querySelector('.driver-popover')
            if (!pop) return null
            const r = pop.getBoundingClientRect()
            const aktif = document.querySelector('.driver-active-element')
            return {
                judul: pop.querySelector('.driver-popover-title')?.innerText.trim(),
                el: aktif && aktif.id !== 'driver-dummy-element' ? aktif.getAttribute('data-tour') : null,
                utuh: r.left >= 0 && r.top >= 0 && r.right <= innerWidth + 1 && r.bottom <= innerHeight + 1,
                lanjut: !!pop.querySelector('.driver-popover-next-btn') && getComputedStyle(pop.querySelector('.driver-popover-next-btn')).display !== 'none',
                // Elemen yang lebih lebar dari wadah gulirnya (baris tabel di
                // ponsel) harus tampak AWALNYA — di situlah nama barisnya.
                awalTertutup: (() => {
                    if (!aktif || aktif.id === 'driver-dummy-element') return false
                    let w = aktif.parentElement
                    while (w && w !== document.body && !(w.scrollWidth > w.clientWidth && /auto|scroll|hidden/.test(getComputedStyle(w).overflowX))) w = w.parentElement
                    if (!w || w === document.body) return false
                    const ra = aktif.getBoundingClientRect()
                    return ra.width > w.clientWidth && ra.left < w.getBoundingClientRect().left - 2
                })(),
            }
        })
        if (!info) break
        const langkah = judulKeLangkah.get(info.judul)
        if (!langkah) masalah.push(`judul asing "${info.judul}"`)
        else {
            tampil.push(langkah.id)
            if ((langkah.el ?? null) !== info.el) masalah.push(`${langkah.id}: menyorot ${info.el ?? 'tengah'}, bukan ${langkah.el ?? 'tengah'}`)
        }
        if (!info.utuh) masalah.push(`${langkah?.id ?? info.judul}: kotak panduan keluar layar`)
        if (info.awalTertutup) masalah.push(`${langkah?.id ?? info.judul}: awal elemen tergulir keluar tabelnya`)
        if (TANGKAPAN) {
            mkdirSync(TANGKAPAN, { recursive: true })
            await page.screenshot({ path: `${TANGKAPAN}/${lebar}${jalan.replace(/\//g, '_')}-${String(i + 1).padStart(2, '0')}.png` })
        }
        if (!info.lanjut) break
        await page.click('.driver-popover-next-btn')
    }
    await tutupPanduan(page)
    if (galatJs.length) masalah.push(...galatJs.map((g) => `JS: ${g.slice(0, 120)}`))

    const dilewati = varian.steps.filter((s) => s.el && !tampil.includes(s.id)).map((s) => s.id)
    const gagal = masalah.length > 0 || tampil.length === 0
    if (gagal) jumlahGagal++
    baris.push(`${gagal ? 'GAGAL' : 'ok   '} ${lebar}px ${jalan} [${varian.key}] ${tampil.length}/${varian.steps.length} langkah`
        + (dilewati.length ? ` · dilewati: ${dilewati.join(', ')}` : '')
        + (terlihat.length === 0 && varian.steps.some((s) => s.el) ? ' · (tidak ada elemen terlihat saat dibuka)' : ''))
    for (const m of masalah) baris.push(`        ${m}`)
}

async function cariRincian(page) {
    const jalan = []
    for (const induk of RINCIAN_DARI) {
        try {
            await page.goto(`${BASE}${induk}`, { waitUntil: 'networkidle2', timeout: 30000 })
        }
        catch { continue }
        const pola = new RegExp(`^${induk.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/\\d+(/edit)?$`)
        const hrefs = await page.$$eval('a[href]', as => as.map(a => new URL(a.href).pathname))
        const unik = [...new Set(hrefs)].filter(h => pola.test(h))
        // Satu rincian dan satu halaman ubah per modul cukup — kecuali modul
        // yang rinciannya berbeda per status.
        const rincian = unik.filter(h => !h.endsWith('/edit')).slice(0, BEBERAPA_KEADAAN.has(induk) ? 3 : 1)
        for (const pilih of [...rincian, unik.find(h => h.endsWith('/edit'))]) {
            if (pilih) jalan.push(pilih)
        }
    }
    // Dari Riwayat Transaksi: struk penjualan terakhir dan formulir retur.
    await page.goto(`${BASE}/dashboard/transactions/history`, { waitUntil: 'networkidle2' })
    const dariRiwayat = await page.$$eval('a[href]', as => as.map(a => new URL(a.href).pathname))
    for (const pola of [/\/print$/, /\/sales-return\/create$/]) {
        const ketemu = dariRiwayat.find(h => pola.test(h))
        if (ketemu) jalan.push(ketemu)
    }
    return jalan
}

/**
 * Menu meja pelanggan (/dine/<token>): halaman Meja Dine-In hanya membuka
 * kode QR-nya, jadi tokennya dibaca dari basis data lokal — atau MENU_MEJA.
 */
function cariMenuMeja() {
    if (process.env.MENU_MEJA) return process.env.MENU_MEJA
    try {
        const token = execFileSync('php', ['artisan', 'tinker', '--execute',
            "echo App\\Models\\DiningTable::where('is_active', true)->value('token');"],
        { cwd: new URL('..', import.meta.url).pathname, encoding: 'utf8' }).trim().split('\n').pop()
        return /^[0-9a-f-]{36}$/.test(token) ? `/dine/${token}` : null
    }
    catch {
        return null
    }
}

async function audit(daftar, surel, { rincian = false } = {}) {
    for (const lebar of LEBAR) {
        const ctx = await browser.createBrowserContext()
        const page = await ctx.newPage()
        const galatJs = []
        page.on('pageerror', (e) => galatJs.push(String(e.message ?? e)))
        await page.setViewport(lebar < 800
            ? { width: lebar, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 1 }
            : { width: lebar, height: 860 })
        if (surel) await masuk(page, surel)
        const semua = rincian ? [...daftar, ...await cariRincian(page)] : daftar
        for (const jalan of semua) {
            if (HANYA.length && !HANYA.some(h => jalan === h || jalan.includes(`/${h}`))) continue
            await auditHalaman(page, jalan, lebar, galatJs)
        }
        await ctx.close()
    }
}

if (BANYAK_TOKO) {
    await audit(['/login', '/daftar'], null)
    if (process.env.SUREL) await audit(['/dashboard/langganan'], process.env.SUREL)
    else baris.push('catatan: SUREL kosong — halaman Langganan tidak diaudit')
}
else {
    const menuMeja = cariMenuMeja()
    await audit(['/login', ...(menuMeja ? [menuMeja] : [])], null)
    if (!menuMeja) baris.push('catatan: tidak ada meja dine-in di data demo — menu meja tidak diaudit')
    await audit(DASHBOARD, process.env.SUREL ?? 'arya@gmail.com', { rincian: !HANYA.length })
    // Kasir: layar kasir dengan shift yang memang terbuka di data demo.
    await audit(['/dashboard/transactions', '/dashboard/transactions/history'], 'cashier@gmail.com')
    // Manajer data demo belum membuka shift: layar kasir menampilkan formulir buka shift.
    await audit(['/dashboard/transactions'], 'manager@gmail.com')
}
await browser.close()

console.log(baris.join('\n'))
console.log(`\n${jumlahGagal} halaman×lebar gagal.`)
process.exit(jumlahGagal ? 1 : 0)
