import { Head, Link, useForm } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconLoader2, IconQrcode, IconBuildingStore, IconLockOpen } from "@tabler/icons-react";
import ApplicationLogo from "@/Components/ApplicationLogo";
import AuthBotGuardFields from "@/Components/AuthBotGuardFields";
import { BRAND } from "@/Utils/brand";
import { rupiah } from "@/Utils/rupiah";

/**
 * /daftar — toko baru Aishii POS (AS7, dokumen 24 §4). Satu layar, satu
 * kolom di ponsel. Harganya dibaca dari server (config/langganan.php),
 * tidak diketik di sini.
 */
function Kolom({ label, galat, petunjuk, children }) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">{label}</label>
            {children}
            {petunjuk && !galat && <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{petunjuk}</p>}
            {galat && <p className="mt-1 text-sm text-danger-500">{galat}</p>}
        </div>
    );
}

const kelasIsian = (galat) =>
    `w-full h-12 rounded-xl border-2 px-4 bg-white text-slate-900 placeholder-slate-400 transition-all focus:ring-4 focus:ring-primary-500/20 dark:bg-slate-800 dark:text-white ${
        galat ? "border-danger-500 focus:border-danger-500" : "border-slate-200 focus:border-primary-500 dark:border-slate-700"
    }`;

export default function Daftar({ jenisUsaha = [], hargaPerOutlet, botGuard }) {
    const { t } = useTranslation();
    const honeypotField = botGuard?.honeypot_field || "company_website";
    const tokenField = botGuard?.token_field || "bot_guard_token";
    const { data, setData, post, processing, errors } = useForm({
        nama_toko: "",
        jenis_usaha: jenisUsaha[0] ?? "retail",
        nama: "",
        email: "",
        telepon: "",
        password: "",
        password_confirmation: "",
        [honeypotField]: "",
        [tokenField]: botGuard?.token || "",
    });

    const kirim = (e) => {
        e.preventDefault();
        post("/daftar");
    };

    return (
        <>
            <Head title="Daftarkan toko" />
            <div className="min-h-screen bg-slate-50 px-4 py-8 dark:bg-slate-950 sm:py-12">
                <div className="mx-auto w-full max-w-lg">
                    <Link href="/" className="mb-6 inline-flex items-center gap-3">
                        <ApplicationLogo className="h-11 w-11" />
                        <span className="text-xl font-bold text-slate-900 dark:text-white">{BRAND.name}</span>
                    </Link>

                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white sm:text-3xl">Daftarkan toko</h1>
                    <p className="mt-2 text-slate-600 dark:text-slate-400">
                        Satu layar ini saja. Sesudahnya Anda langsung masuk ke toko Anda sendiri.
                    </p>

                    <div
                        data-harga-daftar
                        className="mt-6 rounded-2xl border border-primary-100 bg-primary-50 p-4 text-sm text-primary-900 dark:border-primary-900 dark:bg-primary-950/40 dark:text-primary-100"
                    >
                        <p className="text-base font-semibold">
                            {rupiah(hargaPerOutlet)} per outlet berjualan per bulan
                        </p>
                        <ul className="mt-2 space-y-1.5">
                            <li className="flex gap-2">
                                <IconLockOpen size={18} className="mt-0.5 shrink-0" />
                                <span>Tanpa masa coba: kasir aktif begitu tagihan pertama lunas.</span>
                            </li>
                            <li className="flex gap-2">
                                <IconQrcode size={18} className="mt-0.5 shrink-0" />
                                <span>Bayar lewat QRIS dari aplikasi pembayaran apa pun.</span>
                            </li>
                            <li className="flex gap-2">
                                <IconBuildingStore size={18} className="mt-0.5 shrink-0" />
                                <span>Gudang pusat dan outlet yang tidak berjualan tidak ditagih.</span>
                            </li>
                        </ul>
                    </div>

                    <form onSubmit={kirim} className="mt-6 space-y-4">
                        <AuthBotGuardFields botGuard={botGuard} data={data} setData={setData} />
                        {errors.human && (
                            <div className="rounded-xl bg-danger-50 px-4 py-3 text-sm text-danger-600 dark:bg-danger-950/40 dark:text-danger-300">
                                {errors.human}
                            </div>
                        )}

                        <Kolom label="Nama toko" galat={errors.nama_toko}>
                            <input
                                name="nama_toko"
                                value={data.nama_toko}
                                onChange={(e) => setData("nama_toko", e.target.value)}
                                placeholder="mis. Toko Sumber Rejeki"
                                className={kelasIsian(errors.nama_toko)}
                                autoComplete="organization"
                            />
                        </Kolom>

                        <Kolom
                            label="Jenis usaha"
                            galat={errors.jenis_usaha}
                            petunjuk="Kategori barang awalnya disiapkan sesuai pilihan ini — bisa diubah kapan saja."
                        >
                            <select
                                name="jenis_usaha"
                                value={data.jenis_usaha}
                                onChange={(e) => setData("jenis_usaha", e.target.value)}
                                className={kelasIsian(errors.jenis_usaha)}
                            >
                                {jenisUsaha.map((jenis) => (
                                    <option key={jenis} value={jenis}>
                                        {t(`setup.businessType.${jenis}`)}
                                    </option>
                                ))}
                            </select>
                        </Kolom>

                        <Kolom label="Nama Anda" galat={errors.nama}>
                            <input
                                name="nama"
                                value={data.nama}
                                onChange={(e) => setData("nama", e.target.value)}
                                className={kelasIsian(errors.nama)}
                                autoComplete="name"
                            />
                        </Kolom>

                        <Kolom label="Surel" galat={errors.email} petunjuk="Dipakai untuk masuk. Satu surel untuk satu toko.">
                            <input
                                type="email"
                                name="email"
                                value={data.email}
                                onChange={(e) => setData("email", e.target.value)}
                                className={kelasIsian(errors.email)}
                                autoComplete="email"
                                inputMode="email"
                            />
                        </Kolom>

                        <Kolom label="Nomor WhatsApp (boleh dikosongkan)" galat={errors.telepon}>
                            <input
                                type="tel"
                                name="telepon"
                                value={data.telepon}
                                onChange={(e) => setData("telepon", e.target.value)}
                                className={kelasIsian(errors.telepon)}
                                autoComplete="tel"
                                inputMode="tel"
                            />
                        </Kolom>

                        <Kolom label="Kata sandi" galat={errors.password} petunjuk="Minimal 8 huruf.">
                            <input
                                type="password"
                                name="password"
                                value={data.password}
                                onChange={(e) => setData("password", e.target.value)}
                                className={kelasIsian(errors.password)}
                                autoComplete="new-password"
                            />
                        </Kolom>

                        <Kolom label="Ulangi kata sandi" galat={errors.password_confirmation}>
                            <input
                                type="password"
                                name="password_confirmation"
                                value={data.password_confirmation}
                                onChange={(e) => setData("password_confirmation", e.target.value)}
                                className={kelasIsian(errors.password_confirmation)}
                                autoComplete="new-password"
                            />
                        </Kolom>

                        <button
                            type="submit"
                            disabled={processing}
                            className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary-600 font-semibold text-white shadow-lg shadow-primary-600/25 transition-colors hover:bg-primary-700 disabled:opacity-60"
                        >
                            {processing ? (
                                <>
                                    <IconLoader2 size={20} className="animate-spin" />
                                    Menyiapkan toko…
                                </>
                            ) : (
                                "Daftarkan toko"
                            )}
                        </button>

                        {/* Tiap toko mendapat basis data sendiri; di TiDB membuatnya
                            terukur ±21 detik (dokumen 25 di repo Aishii). Tanpa kalimat
                            ini, halaman yang diam setengah menit dibaca sebagai macet —
                            dan yang memuat ulang mendaftar dua kali. */}
                        {processing && (
                            <p role="status" className="text-center text-sm text-slate-600 dark:text-slate-400">
                                Basis data toko Anda sedang dibuat — bisa sampai setengah menit.
                                Jangan tutup atau muat ulang halaman ini.
                            </p>
                        )}

                        <p className="text-center text-sm text-slate-600 dark:text-slate-400">
                            Sudah punya toko?{" "}
                            <Link href="/login" className="font-semibold text-primary-600 hover:text-primary-700">
                                Masuk
                            </Link>
                        </p>
                    </form>
                </div>
            </div>
        </>
    );
}
