/**
 * Penerus pos.aishiierp.com → Aishii POS di Google Cloud Run (Tokyo).
 *
 * Kenapa ada (dokumen 25 di repo Aishii, §3 E): Cloud Run hanya melayani
 * Host *.run.app; pemetaan domain bawaannya masih pratinjau dan di Tokyo
 * memutar lewat Amerika; di paket Cloudflare gratis hanya Worker yang bisa
 * mengganti Host. Pasangannya di aplikasi: middleware `TerimaProksi`.
 *
 * Variabel Worker (Settings → Variables and Secrets):
 *   TUJUAN          alamat layanan Cloud Run, mis.
 *                   https://aishii-pos-123456789.asia-northeast1.run.app
 *   RAHASIA_PROKSI  (Secret) sama persis dengan POS_RAHASIA_PROKSI di Cloud Run
 *
 * Batasnya: paket Workers gratis 100 ribu permintaan per hari untuk SELURUH
 * akun Cloudflare. Berkas /build/assets disimpan peramban setahun (Apache),
 * jadi tiap perangkat hanya sekali melewatinya.
 */
export default {
  async fetch(permintaan, env) {
    if (!env.TUJUAN || !env.RAHASIA_PROKSI) {
      return new Response('Aishii POS belum tersambung ke servernya.', { status: 503 })
    }

    const asal = new URL(permintaan.url)
    const tujuan = new URL(asal.pathname + asal.search, env.TUJUAN)

    const kepala = new Headers(permintaan.headers)
    // Host mengikuti alamat tujuan; yang dari pengunjung tidak ikut.
    kepala.delete('host')
    // Dua kepala ini HANYA boleh ditulis Worker — yang dikirim pengunjung
    // ditimpa, bukan diteruskan.
    kepala.set('X-Aishii-Proksi', env.RAHASIA_PROKSI)
    kepala.set('X-Aishii-Klien', permintaan.headers.get('CF-Connecting-IP') ?? '')

    const adaIsi = !['GET', 'HEAD'].includes(permintaan.method)

    return fetch(tujuan, {
      method: permintaan.method,
      headers: kepala,
      body: adaIsi ? permintaan.body : undefined,
      ...(adaIsi ? { duplex: 'half' } : {}),
      // Pengalihan (masuk, keluar, simpan) diteruskan apa adanya ke
      // peramban — Worker yang mengikutinya sendiri membuat alamat di bilah
      // peramban tidak pernah berpindah.
      redirect: 'manual',
    })
  },
}
