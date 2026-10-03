/**
 * AUDIT PONSEL — mengukur luber mendatar tiap halaman di lebar ponsel.
 *
 * Kenapa ada (AS3, `docs/permintaan-3okt.md` di repo Aishii): "pastikan
 * mobile friendly" tidak bisa dibuktikan dengan membaca kelas Tailwind.
 * Halaman yang bisa digeser ke samping sedikit saja terbaca sebagai aplikasi
 * yang belum jadi — dan sebabnya hampir tidak pernah terlihat dari kodenya
 * (satu baris tombol yang tidak boleh membungkus, satu tabel tanpa
 * pembungkus gulir). Yang membuktikannya cuma peramban sungguhan.
 *
 * Yang diukur per halaman, per lebar:
 *   - luber dokumen: `scrollWidth - clientWidth` elemen penggulir halaman;
 *   - penggulir TAK SENGAJA: elemen yang bisa digeser mendatar padahal
 *     kelasnya tidak meminta `overflow-x-auto/scroll` — biasanya `<main>`
 *     yang ber-`overflow-y-auto` (yang diam-diam ikut menggulir mendatar);
 *   - pelaku: elemen yang tepi kanannya melewati layar dan TIDAK berada di
 *     dalam penggulir yang disengaja (tabel lebar di dalam pembungkus
 *     `overflow-x-auto` itu sah, dan memang pola yang dipakai).
 *
 * Pakai (server Laravel sudah berjalan, data demo terpasang):
 *   php artisan seed:demo --force && php artisan serve --port=8000
 *   node scripts/audit-ponsel.mjs                  # semua halaman, 390 + 320
 *   HANYA=dashboard,login node scripts/audit-ponsel.mjs
 *   TANGKAPAN=/tmp/tangkapan node scripts/audit-ponsel.mjs
 *
 * Keluar 1 bila ada satu luber pun. Biner Chromium dari `CHROMIUM`, atau
 * lokasi bawaan Playwright di kontainer pengembangan.
 */
import puppeteer from 'puppeteer'
import { existsSync, mkdirSync } from 'node:fs'

const BASE = process.env.BASE ?? 'http://127.0.0.1:8000'
const LEBAR = (process.env.LEBAR ?? '390,320').split(',').map(Number)
const TANGKAPAN = process.env.TANGKAPAN ?? ''
const HANYA = (process.env.HANYA ?? '').split(',').map(s => s.trim()).filter(Boolean)
const CHROMIUM = process.env.CHROMIUM
    ?? ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome']
        .find(p => existsSync(p))

const PUBLIK = ['/', '/login', '/forgot-password', '/fitur', '/dokumentasi', '/roadmap', '/kontribusi']

const DASHBOARD = [
    '/dashboard', '/dashboard/transactions', '/dashboard/transactions/history',
    '/dashboard/products', '/dashboard/products/create', '/dashboard/categories',
    '/dashboard/categories/create', '/dashboard/customers', '/dashboard/customers/create',
    '/dashboard/members', '/dashboard/members/create', '/dashboard/customer-segments',
    '/dashboard/customer-vouchers', '/dashboard/crm-campaigns', '/dashboard/crm-reminders',
    '/dashboard/suppliers', '/dashboard/purchase-orders', '/dashboard/purchase-orders/create',
    '/dashboard/goods-receivings', '/dashboard/goods-receivings/create',
    '/dashboard/supplier-returns', '/dashboard/supplier-returns/create', '/dashboard/payables',
    '/dashboard/receivables', '/dashboard/receivables/aging', '/dashboard/aging',
    '/dashboard/stock-mutations', '/dashboard/stock-opnames', '/dashboard/stock-opnames/create',
    '/dashboard/stock-transfers', '/dashboard/stock-transfers/create', '/dashboard/sales-returns',
    '/dashboard/cashier-shifts', '/dashboard/discount-approvals', '/dashboard/pricing-rules',
    '/dashboard/pricing-rules/create', '/dashboard/dine-areas', '/dashboard/dine-tables',
    '/dashboard/dine-orders', '/dashboard/reports/sales', '/dashboard/reports/profits',
    '/dashboard/reports/insights', '/dashboard/users', '/dashboard/users/create',
    '/dashboard/roles', '/dashboard/permissions', '/dashboard/audit-logs', '/dashboard/profile',
    '/dashboard/settings/store', '/dashboard/settings/payments', '/dashboard/settings/bank-accounts',
    '/dashboard/settings/loyalty', '/dashboard/settings/outlets', '/dashboard/settings/price-lists',
    '/dashboard/settings/printer', '/dashboard/settings/target', '/dashboard/settings/units',
    '/dashboard/settings/warehouses', '/dashboard/settings/whatsapp',
]

/** Dijalankan DI DALAM halaman. */
function ukur() {
    const vw = document.documentElement.clientWidth
    const nama = (el) => {
        let s = el.tagName.toLowerCase()
        if (el.id) s += `#${el.id}`
        const kelas = typeof el.className === 'string' ? el.className.trim().split(/\s+/).slice(0, 4).join('.') : ''
        if (kelas) s += `.${kelas}`
        const teks = (el.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 40)
        return teks ? `${s} «${teks}»` : s
    }
    // Panel geser yang tertutup (laci notifikasi, sidebar ponsel) digeser ke
    // luar layar dengan transform — isinya tidak terlihat dan bukan luber.
    const diPanelTertutup = (el) => {
        for (let p = el; p && p !== document.body; p = p.parentElement) {
            if (getComputedStyle(p).position !== 'fixed') continue
            const r = p.getBoundingClientRect()
            return r.left >= vw - 1 || r.right <= 1
        }
        return false
    }
    const interaktif = (el) => el.matches('a[href], button, input:not([type=hidden]), select, textarea, [role=button]')
    const sengaja = (el) => /(^|\s)(md:|lg:|sm:)?overflow-x-(auto|scroll)(\s|$)|(^|\s)overflow-(auto|scroll)(\s|$)/.test(typeof el.className === 'string' ? el.className : '')
    const penggulirTakSengaja = []
    for (const el of document.querySelectorAll('body *')) {
        const cs = getComputedStyle(el)
        if (!['auto', 'scroll'].includes(cs.overflowX)) continue
        if (el.scrollWidth <= el.clientWidth + 1) continue
        if (el.getBoundingClientRect().width === 0) continue
        if (diPanelTertutup(el)) continue
        if (!sengaja(el)) penggulirTakSengaja.push({ el: nama(el), lebih: el.scrollWidth - el.clientWidth })
    }
    // Penggulir PERTAMA di atas elemen yang memutuskan: bila ia penggulir
    // yang disengaja (tabel lebar), elemennya sah; bila ia `<main>` yang
    // cuma ber-overflow-y, elemennya pelaku — walau tata letak di atasnya
    // ber-overflow-hidden.
    const dalamPenggulirSengaja = (el) => {
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const cs = getComputedStyle(p)
            if (['auto', 'scroll'].includes(cs.overflowX)) return sengaja(p)
            if (['hidden', 'clip'].includes(cs.overflowX)) return true
            if (cs.position === 'fixed') return false
        }
        return false
    }
    // Penggulir mendatar yang SAH (kelasnya meminta overflow-x): isinya
    // memang bisa dicapai dengan menggeser, jadi tombol di sana tidak hilang.
    const dalamPenggulirYangBisaDigeser = (el) => {
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const cs = getComputedStyle(p)
            if (['auto', 'scroll'].includes(cs.overflowX) && sengaja(p)) return true
            if (cs.position === 'fixed') return false
        }
        return false
    }
    const pelaku = []
    const terpotong = []
    for (const el of document.querySelectorAll('body *')) {
        const r = el.getBoundingClientRect()
        if (r.width === 0 || r.height === 0) continue
        if (r.right <= vw + 1 && r.left >= -1) continue
        const cs = getComputedStyle(el)
        if (cs.visibility === 'hidden' || cs.display === 'none') continue
        if (diPanelTertutup(el)) continue
        // Tombol yang terpotong oleh induk ber-overflow-hidden tidak membuat
        // halaman bisa digeser — tetapi ia TIDAK BISA DIKETUK, dan itu lebih
        // buruk daripada luber: yang mencarinya menyimpulkan fiturnya tidak ada.
        if (interaktif(el) && !dalamPenggulirYangBisaDigeser(el)) {
            terpotong.push({ el: nama(el), kiri: Math.round(r.left), kanan: Math.round(r.right) })
            continue
        }
        if (r.right <= vw + 1) continue
        if (dalamPenggulirSengaja(el)) continue
        // Yang dilaporkan cuma yang PALING LUAR — anaknya ikut melewati layar
        // karena induknya, dan menyebut semuanya mengubur pelakunya.
        if (el.parentElement && el.parentElement.getBoundingClientRect().right > vw + 1 && !dalamPenggulirSengaja(el.parentElement) && el.parentElement !== document.body) continue
        pelaku.push({ el: nama(el), kanan: Math.round(r.right), lebar: Math.round(r.width) })
    }
    // Teks yang MELUBER DARI KOTAKNYA sendiri tanpa menyeret halaman —
    // angka rupiah 2xl di kartu sempit menembus garis kartunya. Tidak
    // terbaca sebagai luber halaman, tetapi terbaca sebagai aplikasi rusak.
    const meluberKotak = []
    for (const el of document.querySelectorAll('body p, body span, body h1, body h2, body h3, body h4, body a, body button, body label, body strong, body td, body th')) {
        if (diPanelTertutup(el)) continue
        const cs = getComputedStyle(el)
        if (cs.overflowX !== 'visible' || cs.display === 'inline') continue
        if (el.clientWidth === 0 || el.scrollWidth <= el.clientWidth + 2) continue
        if (dalamPenggulirYangBisaDigeser(el)) continue
        const r = el.getBoundingClientRect()
        if (r.right < 0 || r.left > vw) continue
        // Yang diukur TEKSNYA, bukan sembarang anak: lencana angka yang
        // sengaja ditempel `absolute -right-2` di pojok tombol lonceng bukan
        // teks yang meluber, dan ia ikut terhitung di scrollWidth.
        const batasKanan = r.right - parseFloat(cs.borderRightWidth) + 2
        let tumpah = 0
        const jalan = document.createTreeWalker(el, NodeFilter.SHOW_TEXT)
        for (let t = jalan.nextNode(); t; t = jalan.nextNode()) {
            if (!t.textContent.trim()) continue
            let dalamAbsolut = false
            for (let q = t.parentElement; q && q !== el; q = q.parentElement) {
                if (['absolute', 'fixed'].includes(getComputedStyle(q).position)) { dalamAbsolut = true; break }
            }
            if (dalamAbsolut) continue
            const rg = document.createRange()
            rg.selectNodeContents(t)
            for (const kotak of rg.getClientRects()) tumpah = Math.max(tumpah, kotak.right - batasKanan)
        }
        if (tumpah > 0) meluberKotak.push({ el: nama(el), lebih: Math.round(tumpah + 2) })
    }
    const doc = document.scrollingElement
    return {
        meluberKotak: meluberKotak.slice(0, 6),
        vw,
        luberDokumen: doc.scrollWidth - doc.clientWidth,
        penggulirTakSengaja: penggulirTakSengaja.slice(0, 6),
        pelaku: pelaku.slice(0, 6),
        terpotong: terpotong.slice(0, 8),
    }
}

const browser = await puppeteer.launch({
    executablePath: CHROMIUM,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
})

const tunggu = (ms) => new Promise(r => setTimeout(r, ms))

async function masuk(page, surel) {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle2' })
    await page.type('input[name=email]', surel)
    await page.type('input[name=password]', 'password')
    // Penjaga bot menolak kiriman yang lebih cepat dari dua detik.
    await tunggu(2500)
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
        page.click('button[type=submit]'),
    ])
    if (new URL(page.url()).pathname.startsWith('/login')) throw new Error(`gagal masuk sebagai ${surel}`)
    // Tur pengenalan menutupi layar pada kunjungan pertama tiap halaman, dan
    // yang diukur di sini tata letaknya — jadi turnya ditandai selesai.
    await page.evaluate(() => Promise.all(['dashboard', 'pos', 'products', 'cashier_shifts', 'reports']
        .map(t => window.axios.post(`/dashboard/tours/${t}/complete`).catch(() => {}))))
}

let jumlahLuber = 0
const baris = []

/**
 * Halaman RINCIAN (ubah/lihat satu baris) tidak punya alamat tetap — id-nya
 * dibaca dari tautan pertama di halaman daftarnya. Yang tidak menemukan
 * tautan apa pun menyingkir diam-diam: data demo tidak selalu memuatnya.
 */
const RINCIAN_DARI = [
    '/dashboard/products', '/dashboard/customers', '/dashboard/members', '/dashboard/suppliers',
    '/dashboard/purchase-orders', '/dashboard/goods-receivings', '/dashboard/supplier-returns',
    '/dashboard/receivables', '/dashboard/payables', '/dashboard/stock-opnames',
    '/dashboard/stock-transfers', '/dashboard/sales-returns', '/dashboard/cashier-shifts',
    '/dashboard/pricing-rules', '/dashboard/users', '/dashboard/roles', '/dashboard/categories',
    '/dashboard/settings/warehouses', '/dashboard/settings/outlets', '/dashboard/crm-campaigns',
    '/dashboard/customer-segments', '/dashboard/customer-vouchers',
]

async function cariRincian(page) {
    const jalan = []
    for (const induk of RINCIAN_DARI) {
        try {
            await page.goto(`${BASE}${induk}`, { waitUntil: 'networkidle2', timeout: 30000 })
        }
        catch { continue }
        const pola = new RegExp(`^${induk.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/\\d+(/edit)?$`)
        const hrefs = await page.$$eval('a[href]', as => as.map(a => new URL(a.href).pathname))
        for (const h of [...new Set(hrefs)].filter(h => pola.test(h)).slice(0, 2)) jalan.push(h)
    }
    return jalan
}

async function audit(daftar, surel, { rincian = false } = {}) {
    for (const lebar of LEBAR) {
        const ctx = await browser.createBrowserContext()
        const page = await ctx.newPage()
        await page.setViewport({ width: lebar, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 1 })
        // Bahasa ponsel yang paling lazim di Indonesia: Inggris. Yang diuji
        // justru apakah aplikasinya tetap berbahasa Indonesia.
        await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' })
        if (surel) await masuk(page, surel)
        const semua = rincian ? [...daftar, ...await cariRincian(page)] : daftar
        for (const jalan of semua) {
            if (HANYA.length && !HANYA.some(h => jalan === h || jalan.endsWith(`/${h}`))) continue
            let status = 0
            try {
                const res = await page.goto(`${BASE}${jalan}`, { waitUntil: 'networkidle2', timeout: 30000 })
                status = res?.status() ?? 0
            }
            catch (e) {
                baris.push(`${lebar}px ${jalan}: GAGAL MEMBUKA ${String(e).slice(0, 80)}`)
                continue
            }
            await tunggu(400)
            const akhir = new URL(page.url()).pathname
            const m = await page.evaluate(ukur)
            const luber = m.luberDokumen > 0 || m.penggulirTakSengaja.length > 0 || m.pelaku.length > 0 || m.terpotong.length > 0 || m.meluberKotak.length > 0
            if (luber) jumlahLuber++
            const tanda = luber ? 'LUBER' : 'ok   '
            const alih = akhir !== jalan ? ` → ${akhir}` : ''
            baris.push(`${tanda} ${lebar}px ${jalan}${alih} [${status}] dok=${m.luberDokumen}`)
            for (const p of m.penggulirTakSengaja) baris.push(`        penggulir tak sengaja +${p.lebih}px: ${p.el}`)
            for (const p of m.pelaku) baris.push(`        pelaku kanan=${p.kanan} lebar=${p.lebar}: ${p.el}`)
            for (const p of m.terpotong) baris.push(`        terpotong ${p.kiri}..${p.kanan}: ${p.el}`)
            for (const p of m.meluberKotak) baris.push(`        meluber dari kotaknya +${p.lebih}px: ${p.el}`)
            if (TANGKAPAN) {
                mkdirSync(TANGKAPAN, { recursive: true })
                const berkas = `${TANGKAPAN}/${lebar}${jalan.replace(/\//g, '_') || '_akar'}.png`
                await page.screenshot({ path: berkas })
            }
        }
        await ctx.close()
    }
}

await audit(PUBLIK, null)
await audit(DASHBOARD, process.env.SUREL ?? 'arya@gmail.com', { rincian: !HANYA.length })
// Kasir: layar yang paling sering dibuka di ponsel, dengan shift yang
// memang terbuka di data demo — layar kasir sungguhan, bukan formulir shift.
await audit(['/dashboard/transactions', '/dashboard/transactions/history'], 'cashier@gmail.com')
await browser.close()

console.log(baris.join('\n'))
console.log(`\n${jumlahLuber} halaman×lebar meluber.`)
process.exit(jumlahLuber ? 1 : 0)
