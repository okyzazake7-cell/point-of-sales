import { Head, Link } from "@inertiajs/react";
import PublicLayout from "@/Layouts/PublicLayout";
import { IconArrowRight, IconCheck, IconHistory } from "@tabler/icons-react";
import { BRAND } from "@/Utils/brand";

/*
 * RIWAYAT VERSI, BUKAN "PERJALANAN".
 *
 * Versi pembuat aslinya berjudul "Perjalanan Dikasir" dan ditutup "Arah ke
 * Depan" — rencana komunitas proyek hulu. Di bawah merek Aishii keduanya
 * berbohong dengan cara yang berbeda: riwayat proyek orang lain yang diberi
 * nama kita, dan rencana yang tidak pernah kita janjikan. Yang tersisa di
 * sini cuma yang sudah TERJADI, dengan asal-usulnya disebut — dan tanpa satu
 * tanggal pun untuk yang belum ada.
 *
 * Label "Rilis saat ini" juga dicabut: ia menempel di v2.10.4 sementara kode
 * yang berjalan sudah v3.0.3. Label yang harus diingat diperbarui tangan
 * adalah label yang basi.
 */
const rilis = [
    {
        version: BRAND.name,
        tag: "Keluarga Aishii",
        asal: "aishii",
        date: "Okt 2026",
        items: [
            "Merek, warna, dan huruf keluarga Aishii",
            "Ramah ponsel: diukur di lebar 320 dan 390 piksel, tanpa halaman yang bisa digeser ke samping",
            "Bahasa Indonesia sejak kunjungan pertama, apa pun bahasa HP-nya",
            "Pemindai barcode kamera dan printer struk USB tidak lagi diblokir header keamanan",
            "Menu akun dengan Profil dan Keluar di ponsel",
        ],
    },
    {
        version: "v3.0.3",
        tag: "Keamanan",
        date: "Sep 2026",
        items: [
            "Token API kedaluwarsa sesuai pengaturan",
            "Endpoint kasir API menuntut hak akses kasir",
            "Penjaga hak istimewa dan verifikasi webhook",
        ],
    },
    {
        version: "v3.0.1–v3.0.2",
        tag: "Pemeliharaan",
        date: "Sep 2026",
        items: [
            "Proses checkout web dan API memakai satu layanan yang sama",
            "Uji otomatis berjalan di setiap perubahan",
        ],
    },
    {
        version: "v3.0.0",
        tag: "Multi-outlet",
        date: "Sep 2026",
        items: [
            "Outlet sebagai batas data dan operasional",
            "Pengaturan per outlet dengan cadangan global",
            "Pemilih outlet di bilah atas",
        ],
    },
    {
        version: "v2.10",
        tag: "Pencetakan",
        date: "Sep 2026",
        items: ["Cetak otomatis dan ESC/POS lewat WebUSB"],
    },
    {
        version: "v2.8–v2.9",
        tag: "Operasional kasir",
        date: "Sep 2026",
        items: [
            "Sinkronisasi transaksi luring dan QRIS dinamis",
            "Kas masuk-keluar shift, laporan X/Z, dan jenis pesanan",
        ],
    },
    {
        version: "v2.4–v2.7",
        tag: "Dine-in & pengenalan",
        date: "Sep 2026",
        items: [
            "Menu QR dan pesanan mandiri dari meja",
            "Wizard pemasangan pertama, tur, dan daftar periksa penyiapan",
        ],
    },
    {
        version: "v2.0–v2.3",
        tag: "Fondasi",
        date: "2025–2026",
        items: [
            "Tampilan baru yang responsif",
            "WhatsApp gateway dan pemberitahuan stok",
            "Dua bahasa: Indonesia dan Inggris",
        ],
    },
];

export default function Roadmap() {
    return (
        <PublicLayout active="/roadmap">
            <Head title="Riwayat versi" />

            <section className="px-4 pt-16 pb-14 sm:px-6 sm:pt-20 bg-gradient-to-b from-primary-50 dark:from-primary-950/40 to-transparent">
                <div className="mx-auto max-w-3xl text-center">
                    <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-primary-100 bg-primary-50 px-4 py-2 text-sm font-medium text-primary-700 dark:border-primary-900 dark:bg-primary-950/50 dark:text-primary-300">
                        <IconHistory size={16} />
                        Riwayat versi
                    </div>
                    <h1 className="text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white md:text-5xl">
                        Apa yang berubah, dan dari mana asalnya
                    </h1>
                    <p className="mx-auto mt-5 max-w-2xl text-lg text-slate-600 dark:text-slate-400">
                        {BRAND.name} berangkat dari proyek open-source {BRAND.upstream.name} karya{" "}
                        {BRAND.upstream.author}. Versi bernomor di bawah adalah riwayat proyek aslinya —
                        fondasi yang kini dipakai {BRAND.name}. Yang terbaru di atas.
                    </p>
                </div>
            </section>

            <section className="px-4 pb-20 sm:px-6">
                <div className="mx-auto max-w-3xl">
                    <div className="relative space-y-10 border-l-2 border-primary-200 pl-8 dark:border-primary-900">
                        {rilis.map((rel) => (
                            <div key={rel.version} className="relative">
                                <div className="absolute -left-[41px] top-1.5 size-5 rounded-full border-4 border-white bg-gradient-to-br from-primary-500 to-primary-700 dark:border-slate-950" />
                                <div className="flex flex-wrap items-center gap-3">
                                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">{rel.version}</h2>
                                    <span className="rounded-full border border-primary-100 bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-700 dark:border-primary-900 dark:bg-primary-950/60 dark:text-primary-300">
                                        {rel.tag}
                                    </span>
                                    {rel.asal !== "aishii" && (
                                        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                            Proyek asli
                                        </span>
                                    )}
                                    <span className="text-sm text-slate-500">{rel.date}</span>
                                </div>
                                <ul className="mt-3 space-y-2">
                                    {rel.items.map((item) => (
                                        <li key={item} className="flex items-start gap-2.5">
                                            <IconCheck size={16} className="mt-1 shrink-0 text-success-500" />
                                            <span className="text-sm text-slate-600 dark:text-slate-300">{item}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>

                    <p className="mt-12 text-sm text-slate-500 dark:text-slate-400">
                        Rincian tiap versi proyek aslinya ada di berkas CHANGELOG kode sumbernya.{" "}
                        <Link href="/kontribusi" className="inline-flex items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400">
                            Kode sumber
                            <IconArrowRight size={14} />
                        </Link>
                    </p>
                </div>
            </section>
        </PublicLayout>
    );
}
