/**
 * UJI PERAMBAN — antrean penjualan luring per toko (AS12, docs/permintaan-
 * 3okt.md di repo Aishii). Satu peramban, dua toko, persis keadaan HP kasir
 * yang dipakai bergantian.
 *
 * Membuktikan, di Chromium sungguhan dengan service worker hidup:
 *   L1  layar kasir toko A membuka IndexedDB MILIK toko A (`pos-offline-t<A>`);
 *   L2  penjualan di antrean toko A TERKIRIM lewat sesi layar kasir dan
 *       dijawab `synced` — dulu jalur API bertoken menjawab 401 dan
 *       penjualannya tidak pernah sampai;
 *   L3  sesudah kasir toko B masuk di peramban yang sama, antrean toko A
 *       tidak terbaca (tidak ada spanduk "menunggu sinkronisasi"), tidak
 *       terkirim ke toko B, dan B membuka IndexedDB-nya sendiri;
 *   L4  tembolok service worker toko A sudah dikosongkan begitu B masuk.
 *
 * Pakai (server mode banyak toko di BASE, toko A aktif dengan data demo dan
 * kasir bershift terbuka, toko B pemilik bernomor pengguna 1 tanpa shift):
 *   BASE=http://127.0.0.1:8010 node scripts/uji-luring-per-toko.mjs
 * Keluar 1 bila satu asersi pun gagal.
 */
import puppeteer from 'puppeteer'
import { existsSync } from 'node:fs'

const BASE = process.env.BASE ?? 'http://127.0.0.1:8010'
const SUREL_A = process.env.SUREL_A ?? 'cashier@gmail.com'
const SUREL_B = process.env.SUREL_B ?? 'budi@contoh.id'
const CHROMIUM = process.env.CHROMIUM
    ?? ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome']
        .find(p => existsSync(p))
const tunggu = (ms) => new Promise(r => setTimeout(r, ms))

let gagal = 0
const hasil = []
function uji(nama, lulus, rinci = '') {
    hasil.push(`${lulus ? 'LULUS' : 'GAGAL'} ${nama}${rinci ? ` — ${rinci}` : ''}`)
    if (!lulus) gagal++
}

const browser = await puppeteer.launch({ executablePath: CHROMIUM, args: ['--no-sandbox', '--disable-dev-shm-usage'], protocolTimeout: 30000 })
const ctx = await browser.createBrowserContext()
const page = await ctx.newPage()
await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true })

const kirimanSinkron = []
page.on('request', (r) => {
    if (r.method() === 'POST' && /\/(transactions\/sync-offline|pos\/transactions\/sync)/.test(r.url())) {
        kirimanSinkron.push({ url: r.url(), body: r.postData() ?? '' })
    }
})
const jawabanSinkron = []
page.on('response', async (r) => {
    if (/\/(transactions\/sync-offline|pos\/transactions\/sync)/.test(r.url())) {
        jawabanSinkron.push({ status: r.status(), body: await r.text().catch(() => '') })
    }
})

async function masuk(surel) {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle2' })
    await page.type('input[name=email]', surel)
    await page.type('input[name=password]', 'password')
    await tunggu(2500)
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}), page.click('button[type=submit]')])
    await page.evaluate(() => Promise.all(['dashboard', 'pos', 'products', 'cashier_shifts', 'reports']
        .map(t => window.axios.post(`/dashboard/tours/${t}/complete`).catch(() => {}))))
}

const props = () => page.evaluate(() => {
    const p = window.history.state?.page?.props ?? {}
    return { toko: p.toko ?? null, user: p.auth?.user?.id ?? null, shift: p.activeCashierShift?.warehouse_id ?? null }
})
const namaDb = () => page.evaluate(async () => (await indexedDB.databases()).map(d => d.name).filter(n => n?.startsWith('pos-offline')))

/** Menulis satu baris antrean ke basis data IndexedDB bernama `nama`, bentuk offlineDb v2. */
const tulisAntrean = (nama, baris) => page.evaluate((nama, baris) => new Promise((ok, tolak) => {
    const r = indexedDB.open(nama)
    r.onerror = () => tolak(r.error)
    r.onsuccess = () => {
        const db = r.result
        try {
            const tx = db.transaction('pending_transactions', 'readwrite')
            tx.objectStore('pending_transactions').add(baris)
            tx.oncomplete = () => { db.close(); ok(true) }
            tx.onerror = () => tolak(tx.error)
        } catch (e) {
            db.close()
            tolak(e)
        }
    }
}), nama, baris)

const isiAntrean = (nama) => page.evaluate((nama) => new Promise((ok) => {
    const r = indexedDB.open(nama)
    r.onsuccess = () => {
        const db = r.result
        if (!db.objectStoreNames.contains('pending_transactions')) { db.close(); return ok([]) }
        const q = db.transaction('pending_transactions').objectStore('pending_transactions').getAll()
        q.onsuccess = () => { db.close(); ok(q.result) }
    }
    r.onerror = () => ok([])
}), nama)

// ── Toko A ──────────────────────────────────────────────────────────────
await masuk(SUREL_A)
await page.goto(`${BASE}/dashboard/products`, { waitUntil: 'networkidle2' })
await page.goto(`${BASE}/dashboard/transactions`, { waitUntil: 'networkidle2' })
await tunggu(1500)
const a = await props()
const dbDiharapkan = `pos-offline-t${a.toko?.id}`
const dbAda = await namaDb()
uji('L1 layar kasir toko A membuka IndexedDB milik toko A', dbAda.includes(dbDiharapkan), JSON.stringify(dbAda))
// Dunia lama tidak punya basis data per toko: antreannya ditulis ke basis
// data yang MEMANG dipakai aplikasi, supaya kebocorannya tampil sebagai
// asersi merah di L3, bukan sebagai skrip yang menunggu selamanya.
const dbA = dbAda.includes(dbDiharapkan) ? dbDiharapkan : (dbAda[0] ?? 'pos-offline')

const produk = await page.evaluate(() => window.history.state?.page?.props?.products?.data?.[0]?.id
    ?? window.history.state?.page?.props?.products?.[0]?.id ?? null)
const uuidA = crypto.randomUUID()
const uuidUntukB = crypto.randomUUID()
const sekarang = new Date().toISOString()
// Penjualan luring toko A yang menunggu dikirim (kunci cakupan kasir A).
await tulisAntrean(dbA, {
    data: { client_uuid: uuidA, items: [{ product_id: produk, qty: 1, unit_id: null }], cash: 100000, payment_method: 'cash' },
    client_uuid: uuidA, scope_key: `${a.user}:${a.shift ?? 'none'}`, status: 'pending', attempts: 0, created_at: sekarang,
})
// Baris kedua berkunci cakupan YANG SAMA dengan pemilik toko B (pengguna 1
// tanpa shift) — di dunia lama inilah yang terbaca dan terkirim ke toko B.
await tulisAntrean(dbA, {
    data: { client_uuid: uuidUntukB, items: [{ product_id: produk, qty: 1, unit_id: null }], cash: 100000, payment_method: 'cash' },
    client_uuid: uuidUntukB, scope_key: '1:none', status: 'pending', attempts: 0, created_at: sekarang,
})

// Muat ulang dalam keadaan daring: layar kasir mengirim antreannya sendiri.
await page.goto(`${BASE}/dashboard/transactions`, { waitUntil: 'networkidle2' })
await tunggu(4000)
const terkirimA = jawabanSinkron.find(j => j.body.includes(uuidA))
uji('L2 antrean toko A terkirim lewat sesi dan dijawab synced',
    Boolean(terkirimA) && terkirimA.status === 200 && /"status":"synced"/.test(terkirimA.body),
    terkirimA ? `${terkirimA.status} ${terkirimA.body.slice(0, 160)}` : `tidak ada jawaban sinkron (kiriman: ${kirimanSinkron.length})`)
uji('L2b baris yang terkirim keluar dari antrean A',
    !(await isiAntrean(dbA)).some(b => b.client_uuid === uuidA))
const adaTembolokA = await page.evaluate(async () => Boolean(await caches.match('/dashboard/products')))

// ── Toko B, peramban yang sama ──────────────────────────────────────────
await page.evaluate(() => window.axios.post('/logout').catch(() => {}))
const kirimanSebelumB = kirimanSinkron.length
await masuk(SUREL_B)
await page.goto(`${BASE}/dashboard/transactions`, { waitUntil: 'networkidle2' })
await tunggu(4000)
const b = await props()
const teks = await page.evaluate(() => document.body.innerText)
uji('L3a layar kasir toko B tidak membaca antrean toko A', !/menunggu sinkronisasi/.test(teks))
uji('L3b antrean toko A tidak terkirim ke toko B',
    !kirimanSinkron.slice(kirimanSebelumB).some(k => k.body.includes(uuidUntukB)))
uji('L3c toko B membuka IndexedDB-nya sendiri',
    b.toko?.id !== a.toko?.id && (await namaDb()).includes(`pos-offline-t${b.toko?.id}`), JSON.stringify(await namaDb()))
uji('L3d baris toko A tetap utuh di tempatnya, menunggu kasir A',
    (await isiAntrean(dbA)).some(r => r.client_uuid === uuidUntukB))
uji('L4 tembolok service worker toko A sudah dikosongkan',
    adaTembolokA ? !(await page.evaluate(async () => Boolean(await caches.match('/dashboard/products')))) : true,
    adaTembolokA ? '' : '(tembolok A tidak pernah terisi — asersi ini tidak menguji apa pun di jalan ini)')

await browser.close()
console.log(hasil.join('\n'))
console.log(`\n${hasil.length - gagal}/${hasil.length} lulus`)
process.exit(gagal ? 1 : 0)
