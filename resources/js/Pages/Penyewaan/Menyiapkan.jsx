import { useEffect, useState } from "react";
import { Head, Link } from "@inertiajs/react";
import { IconAlertTriangle, IconLoader2, IconRefresh, IconWifiOff } from "@tabler/icons-react";
import ApplicationLogo from "@/Components/ApplicationLogo";
import { BRAND } from "@/Utils/brand";

/**
 * Kemajuan pendaftaran toko (AU6, dokumen 28 di repo Aishii).
 *
 * Tiap toko mendapat basis data sendiri: 104 langkah, 427 di antaranya
 * perintah DDL yang di TiDB jauh lebih lambat daripada di MySQL biasa. Dulu
 * semuanya dikerjakan SATU permintaan, dan halaman diam sampai permintaan
 * itu diputus di tengah jalan. Kini halaman ini yang menggerakkannya:
 * memanggil /daftar/lanjut berulang-ulang (tiap panggilan ±20 detik kerja),
 * menampilkan langkah ke berapa, dan mencoba lagi sendiri bila sinyal putus.
 */
const tidur = (ms) => new Promise((selesai) => setTimeout(selesai, ms));

const lamaBerjalan = (detik) => {
    const menit = Math.floor(detik / 60);
    const sisa = detik % 60;
    return menit > 0 ? `${menit} menit ${sisa} detik` : `${sisa} detik`;
};

export default function Menyiapkan({ namaToko, langkah: langkahAwal = 0, dari: dariAwal = 1, gagal = false, detikBerjalan = 0 }) {
    const [langkah, setLangkah] = useState(langkahAwal);
    const [dari, setDari] = useState(dariAwal);
    const [keadaan, setKeadaan] = useState(gagal ? "gagal" : "berjalan");
    const [detik, setDetik] = useState(detikBerjalan);
    const [putaran, setPutaran] = useState(0);

    // Lama berjalan dihitung sejak tombol "Daftarkan toko" ditekan (dari
    // server), bukan sejak halaman ini dimuat ulang.
    useEffect(() => {
        if (keadaan === "gagal" || keadaan === "selesai") return undefined;
        const jam = setInterval(() => setDetik((d) => d + 1), 1000);
        return () => clearInterval(jam);
    }, [keadaan]);

    useEffect(() => {
        if (keadaan === "gagal") return undefined;
        let hidup = true;

        (async () => {
            let jeda = 0;
            while (hidup) {
                try {
                    const { data } = await window.axios.post("/daftar/lanjut");
                    if (!hidup) return;
                    jeda = 0;
                    if (data.langkah !== null && data.langkah !== undefined) setLangkah(data.langkah);
                    if (data.dari) setDari(data.dari);
                    if (data.selesai) {
                        setKeadaan("selesai");
                        window.location.assign(data.menuju);
                        return;
                    }
                    if (data.gagal) {
                        setKeadaan("gagal");
                        return;
                    }
                    setKeadaan("berjalan");
                    // Jendela lain sedang mengerjakan toko yang sama.
                    if (data.sibuk) await tidur(3000);
                } catch (galat) {
                    if (!hidup) return;
                    const status = galat?.response?.status;
                    // Bekal pendaftaran hilang (sesi habis): mulai dari formulir.
                    if (status === 409) {
                        window.location.assign(galat.response.data?.menuju || "/daftar");
                        return;
                    }
                    // Token CSRF basi sesudah lama terbuka: muat ulang, lalu lanjut.
                    if (status === 419) {
                        window.location.reload();
                        return;
                    }
                    setKeadaan("putus");
                    jeda = Math.min(jeda ? jeda * 2 : 2000, 30000);
                    await tidur(jeda);
                }
            }
        })();

        return () => {
            hidup = false;
        };
    }, [putaran]);

    const ulangi = async () => {
        setKeadaan("berjalan");
        setLangkah(0);
        try {
            await window.axios.post("/daftar/ulangi");
        } catch (galat) {
            if (galat?.response?.status === 409) {
                window.location.assign(galat.response.data?.menuju || "/daftar");
                return;
            }
        }
        setPutaran((p) => p + 1);
    };

    const persen = Math.min(100, Math.round((langkah / Math.max(dari, 1)) * 100));

    return (
        <>
            <Head title="Menyiapkan toko" />
            <div className="min-h-screen bg-slate-50 px-4 py-8 dark:bg-slate-950 sm:py-12">
                <div className="mx-auto w-full max-w-lg">
                    <div className="mb-6 inline-flex items-center gap-3">
                        <ApplicationLogo className="h-11 w-11" />
                        <span className="text-xl font-bold text-slate-900 dark:text-white">{BRAND.name}</span>
                    </div>

                    <div
                        data-menyiapkan
                        data-keadaan={keadaan}
                        className="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 sm:p-6"
                    >
                        <div>
                            {/* Nama toko di barisnya sendiri: "Menyiapkan toko Toko Melati"
                                berulang, dan nama toko memang kerap diawali "Toko". */}
                            <h1 className="text-xl font-bold text-slate-900 dark:text-white sm:text-2xl">
                                Menyiapkan toko Anda
                            </h1>
                            <p data-menyiapkan-nama className="mt-1 break-words font-semibold text-primary-700 dark:text-primary-300">
                                {namaToko}
                            </p>
                            <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                                Tiap toko mendapat basis data sendiri, dan menyiapkannya bisa makan beberapa menit.
                                Biarkan halaman ini terbuka — begitu selesai, Anda langsung masuk ke Langganan.
                            </p>
                        </div>

                        <div>
                            <div
                                role="progressbar"
                                aria-label="Kemajuan penyiapan toko"
                                aria-valuemin={0}
                                aria-valuemax={dari}
                                aria-valuenow={langkah}
                                className="h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
                            >
                                <div
                                    className={`h-full rounded-full transition-all duration-500 ${keadaan === "gagal" ? "bg-danger-500" : "bg-primary-600"}`}
                                    style={{ width: `${persen}%` }}
                                />
                            </div>
                            <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm text-slate-600 dark:text-slate-400">
                                <span data-menyiapkan-langkah>
                                    Langkah {langkah} dari {dari}
                                </span>
                                <span data-menyiapkan-lama>{lamaBerjalan(detik)} berjalan</span>
                            </div>
                        </div>

                        {keadaan === "berjalan" && (
                            <p role="status" className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                <IconLoader2 size={18} className="shrink-0 animate-spin text-primary-600" />
                                Sedang bekerja…
                            </p>
                        )}

                        {keadaan === "selesai" && (
                            <p role="status" className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                <IconLoader2 size={18} className="shrink-0 animate-spin text-primary-600" />
                                Toko siap — membuka Langganan…
                            </p>
                        )}

                        {keadaan === "putus" && (
                            <p
                                role="status"
                                data-menyiapkan-putus
                                className="flex items-start gap-2 rounded-xl bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:bg-warning-950/40 dark:text-warning-200"
                            >
                                <IconWifiOff size={18} className="mt-0.5 shrink-0" />
                                Sambungan terputus — mencoba lagi sendiri. Kemajuannya tidak hilang.
                            </p>
                        )}

                        {keadaan === "gagal" && (
                            <div
                                role="alert"
                                data-menyiapkan-gagal
                                className="space-y-3 rounded-xl bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:bg-danger-950/40 dark:text-danger-300"
                            >
                                <p className="flex items-start gap-2">
                                    <IconAlertTriangle size={18} className="mt-0.5 shrink-0" />
                                    Penyiapan toko terhenti. Ulangi dari awal — toko yang sama disiapkan lagi, tidak
                                    ada toko kedua.
                                </p>
                                <button
                                    type="button"
                                    onClick={ulangi}
                                    data-menyiapkan-ulangi
                                    className="flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-primary-600 font-semibold text-white hover:bg-primary-700"
                                >
                                    <IconRefresh size={18} />
                                    Ulangi dari awal
                                </button>
                            </div>
                        )}

                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            Halaman tertutup sebelum selesai? Buka lagi{" "}
                            <Link href="/daftar" className="font-medium text-primary-600 hover:text-primary-700">
                                Daftarkan toko
                            </Link>{" "}
                            dan kirim sekali lagi — toko yang sama disiapkan ulang, bukan toko kedua.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
