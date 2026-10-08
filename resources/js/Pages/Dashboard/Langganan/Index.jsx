import { useEffect, useMemo, useState } from "react";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import toast from "react-hot-toast";
import {
    IconCircleCheck,
    IconLock,
    IconCopy,
    IconRefresh,
    IconBrandWhatsapp,
    IconMinus,
    IconPlus,
    IconBuildingStore,
    IconHistory,
    IconAlertTriangle,
} from "@tabler/icons-react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { rupiah, tanggalJam, tanggalPendek } from "@/Utils/rupiah";

/**
 * Langganan Aishii POS (AS8/AS9, dokumen 24 §5–§7). Ponsel dulu: satu
 * kolom, nominal yang harus ditransfer ditulis BESAR dengan tombol salin —
 * QRIS-nya statis, jadi pembayar mengetik angkanya sendiri, dan satu digit
 * yang meleset membuat pembayaran tidak tercocokkan otomatis.
 */
const kartu = "rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:p-6";

function Penghitung({ nilai, min = 1, max = 50, onChange, label }) {
    return (
        <div className="flex items-center gap-3">
            <button
                type="button"
                aria-label={`Kurangi ${label}`}
                onClick={() => onChange(Math.max(min, nilai - 1))}
                disabled={nilai <= min}
                className="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 text-slate-700 disabled:opacity-40 dark:border-slate-700 dark:text-slate-200"
            >
                <IconMinus size={18} />
            </button>
            <span className="min-w-[2.5rem] text-center text-xl font-bold text-slate-900 dark:text-white">{nilai}</span>
            <button
                type="button"
                aria-label={`Tambah ${label}`}
                onClick={() => onChange(Math.min(max, nilai + 1))}
                disabled={nilai >= max}
                className="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 text-slate-700 disabled:opacity-40 dark:border-slate-700 dark:text-slate-200"
            >
                <IconPlus size={18} />
            </button>
        </div>
    );
}

function StatusLangganan({ ringkasan }) {
    if (ringkasan.terkunci) {
        return (
            <div className="flex items-start gap-3">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-danger-100 text-danger-700 dark:bg-danger-950 dark:text-danger-300">
                    <IconLock size={22} />
                </span>
                <div>
                    <p className="text-lg font-bold text-slate-900 dark:text-white">
                        {ringkasan.pernah_aktif ? `Berakhir ${tanggalPendek(ringkasan.aktif_sampai)}` : "Belum aktif"}
                    </p>
                    <p className="text-sm text-slate-600 dark:text-slate-400">
                        {ringkasan.pernah_aktif
                            ? "Kasir dan pencatatan berhenti sampai diperpanjang. Laporan tetap terbaca, data tidak dihapus."
                            : "Tanpa masa coba: bayar tagihan pertama, dan kasirnya aktif begitu pembayaran terbaca."}
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div className="flex items-start gap-3">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-success-100 text-success-700 dark:bg-success-950 dark:text-success-300">
                <IconCircleCheck size={22} />
            </span>
            <div>
                <p className="text-lg font-bold text-slate-900 dark:text-white">
                    Aktif sampai {tanggalPendek(ringkasan.aktif_sampai)}
                </p>
                <p className="text-sm text-slate-600 dark:text-slate-400">Sisa {ringkasan.sisa_hari} hari.</p>
            </div>
        </div>
    );
}

function TagihanTerbuka({ tagihan, qris, whatsapp, bolehMengatur }) {
    const [tersalin, setTersalin] = useState(false);
    const [memeriksa, setMemeriksa] = useState(false);

    const salin = async () => {
        try {
            await navigator.clipboard.writeText(String(tagihan.total_bayar));
            setTersalin(true);
            setTimeout(() => setTersalin(false), 2500);
        } catch {
            toast.error("Peramban menolak menyalin — ketik angkanya dari layar.");
        }
    };

    const periksa = () =>
        router.post("/dashboard/langganan/periksa", {}, {
            preserveScroll: true,
            onStart: () => setMemeriksa(true),
            onFinish: () => setMemeriksa(false),
        });

    const batalkan = () => {
        if (!window.confirm("Batalkan tagihan ini? JANGAN dibatalkan bila Anda sudah membayarnya.")) return;
        router.post(`/dashboard/langganan/tagihan/${tagihan.id}/batal`, {}, { preserveScroll: true });
    };

    const pesanWa = encodeURIComponent(
        `Halo, saya sudah membayar tagihan Aishii POS ${tagihan.nomor} sebesar ${rupiah(tagihan.total_bayar)}. Bukti transfer terlampir.`
    );

    return (
        <section data-tagihan-terbuka className={kartu}>
            <h2 className="text-base font-semibold text-slate-900 dark:text-white">Tagihan menunggu pembayaran</h2>
            <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
                {tagihan.jenis === "perpanjang"
                    ? `${tagihan.kursi} outlet × ${tagihan.bulan} bulan`
                    : `Tambah ${tagihan.kursi} outlet untuk sisa ${tagihan.hari_prorata} hari`}{" "}
                · berlaku sampai {tanggalJam(tagihan.berlaku_sampai)}
            </p>

            <div className="mt-4 rounded-2xl bg-slate-50 p-4 text-center dark:bg-slate-800/60">
                <p className="text-sm text-slate-600 dark:text-slate-400">Transfer TEPAT sebesar</p>
                <p data-nominal-bayar className="mt-1 break-words text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-4xl">
                    {rupiah(tagihan.total_bayar)}
                </p>
                <button
                    type="button"
                    onClick={salin}
                    className="mt-3 inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                >
                    <IconCopy size={16} />
                    {tersalin ? "Tersalin" : "Salin nominal"}
                </button>
                {tagihan.kode_unik ? (
                    <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
                        {rupiah(tagihan.nominal_dasar)} + kode unik {tagihan.kode_unik}. Kode unik membuat pembayaran Anda
                        dikenali otomatis — jangan dibulatkan.
                    </p>
                ) : (
                    <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
                        Konfirmasi otomatis belum tersedia: sesudah membayar, kirim bukti lewat WhatsApp dan pengelola
                        mengaktifkan langganan Anda.
                    </p>
                )}
            </div>

            <div className="mt-4 flex flex-col items-center">
                <img
                    src={qris.gambar}
                    alt={`QRIS ${qris.nama}`}
                    className="w-full max-w-[16rem] rounded-xl border border-slate-200 dark:border-slate-700"
                    loading="lazy"
                />
                <p className="mt-2 text-center text-xs text-slate-500 dark:text-slate-400">
                    Penerima: {qris.nama} · NMID {qris.nmid}
                </p>
            </div>

            <div className="mt-4 grid gap-2 sm:grid-cols-2">
                <button
                    type="button"
                    onClick={periksa}
                    disabled={memeriksa}
                    className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
                >
                    <IconRefresh size={18} className={memeriksa ? "animate-spin" : ""} />
                    Saya sudah bayar — periksa
                </button>
                <a
                    href={`https://wa.me/${whatsapp}?text=${pesanWa}`}
                    target="_blank"
                    rel="noopener"
                    className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200"
                >
                    <IconBrandWhatsapp size={18} />
                    Kirim bukti lewat WhatsApp
                </a>
            </div>
            {bolehMengatur && (
                <button
                    type="button"
                    onClick={batalkan}
                    className="mt-3 w-full text-center text-sm font-medium text-slate-500 underline-offset-2 hover:underline dark:text-slate-400"
                >
                    Batalkan dan buat tagihan lain
                </button>
            )}
        </section>
    );
}

function BuatTagihan({ ringkasan, pilihanBulan, hargaPerOutlet, hariProrata }) {
    const minimal = Math.max(1, ringkasan.kursi_terpakai);
    const bisaTambah = !ringkasan.terkunci;
    const [jenis, setJenis] = useState("perpanjang");
    const { data, setData, post, processing, errors } = useForm({
        jenis: "perpanjang",
        bulan: pilihanBulan[0] ?? 1,
        kursi: Math.max(minimal, ringkasan.kursi_dibayar || 1),
    });

    const pilihJenis = (j) => {
        setJenis(j);
        setData({ jenis: j, bulan: data.bulan, kursi: j === "perpanjang" ? Math.max(minimal, ringkasan.kursi_dibayar || 1) : 1 });
    };

    // Rumus yang SAMA dengan App\Langganan\Harga — angka di tombol harus
    // angka yang akan ditagih.
    const total = useMemo(() => {
        if (jenis === "perpanjang") return data.kursi * hargaPerOutlet * data.bulan;
        const mentah = (data.kursi * hargaPerOutlet * Math.max(1, ringkasan.sisa_hari)) / hariProrata;
        return Math.max(1000, Math.ceil(mentah / 1000) * 1000);
    }, [jenis, data.kursi, data.bulan, hargaPerOutlet, hariProrata, ringkasan.sisa_hari]);

    const kirim = (e) => {
        e.preventDefault();
        post("/dashboard/langganan/tagihan", { preserveScroll: true });
    };

    return (
        <section data-buat-tagihan className={kartu}>
            <h2 className="text-base font-semibold text-slate-900 dark:text-white">
                {ringkasan.pernah_aktif ? "Perpanjang atau tambah outlet" : "Tagihan pertama"}
            </h2>

            {bisaTambah && (
                <div className="mt-3 grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
                    {[
                        ["perpanjang", "Perpanjang"],
                        ["tambah_outlet", "Tambah outlet"],
                    ].map(([nilai, label]) => (
                        <button
                            key={nilai}
                            type="button"
                            onClick={() => pilihJenis(nilai)}
                            className={`h-10 rounded-lg text-sm font-semibold ${
                                jenis === nilai
                                    ? "bg-white text-primary-700 shadow dark:bg-slate-900 dark:text-primary-300"
                                    : "text-slate-600 dark:text-slate-300"
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            )}

            <form onSubmit={kirim} className="mt-4 space-y-5">
                {jenis === "perpanjang" && (
                    <div>
                        <p className="mb-2 text-sm font-medium text-slate-700 dark:text-slate-300">Lama berlangganan</p>
                        <div className="grid grid-cols-4 gap-2">
                            {pilihanBulan.map((b) => (
                                <button
                                    key={b}
                                    type="button"
                                    onClick={() => setData("bulan", b)}
                                    className={`h-11 rounded-xl border text-sm font-semibold ${
                                        data.bulan === b
                                            ? "border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300"
                                            : "border-slate-200 text-slate-700 dark:border-slate-700 dark:text-slate-300"
                                    }`}
                                >
                                    {b} bln
                                </button>
                            ))}
                        </div>
                        {errors.bulan && <p className="mt-1 text-sm text-danger-500">{errors.bulan}</p>}
                    </div>
                )}

                <div>
                    <p className="mb-2 text-sm font-medium text-slate-700 dark:text-slate-300">
                        {jenis === "perpanjang" ? "Outlet yang berjualan" : "Outlet tambahan"}
                    </p>
                    <Penghitung
                        label="outlet"
                        nilai={data.kursi}
                        min={jenis === "perpanjang" ? minimal : 1}
                        onChange={(n) => setData("kursi", n)}
                    />
                    <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        {jenis === "perpanjang"
                            ? `Sekarang ${ringkasan.kursi_terpakai} outlet berjualan — paling sedikit sebanyak itu.`
                            : `Dihitung harian untuk sisa ${ringkasan.sisa_hari} hari masa aktif; tanggal berakhirnya tidak bergeser.`}
                    </p>
                    {errors.kursi && <p className="mt-1 text-sm text-danger-500">{errors.kursi}</p>}
                    {errors.tagihan && <p className="mt-1 text-sm text-danger-500">{errors.tagihan}</p>}
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="flex h-12 w-full items-center justify-center rounded-xl bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
                >
                    Buat tagihan {rupiah(total)}
                </button>
            </form>
        </section>
    );
}

export default function Index({
    ringkasan,
    tagihan,
    riwayat = [],
    outletBerjualan = [],
    pilihanBulan = [1, 3, 6, 12],
    hargaPerOutlet,
    hariProrata = 30,
    qris,
    whatsapp,
    konfirmasiOtomatis,
    bolehMengatur,
    pesan,
}) {
    const { errors } = usePage().props;

    useEffect(() => {
        if (pesan?.teks) (pesan.jenis === "success" ? toast.success : toast)(pesan.teks, { id: "pesan-langganan" });
    }, [pesan]);

    // Selagi tagihan menunggu, halaman membaca ulang statusnya sendiri:
    // penjadwal server menjemput pembayaran tiap menit.
    useEffect(() => {
        if (!tagihan) return undefined;
        const jeda = setInterval(() => router.reload({ only: ["ringkasan", "tagihan", "riwayat", "langganan"] }), 20000);
        return () => clearInterval(jeda);
    }, [tagihan?.id]);

    const berhentiJual = (outlet) => {
        if (!window.confirm(`Matikan penjualan di ${outlet.name}? Outlet itu tidak lagi ditagih, dan kasirnya tidak bisa berjualan.`)) return;
        router.post(`/dashboard/langganan/outlet/${outlet.id}/berhenti-jual`, {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Langganan" />
            <div className="mx-auto w-full max-w-3xl space-y-4">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Langganan</h1>
                    <p className="text-sm text-slate-600 dark:text-slate-400">
                        {rupiah(hargaPerOutlet)} per outlet berjualan per bulan.
                    </p>
                </div>

                <section className={kartu}>
                    <StatusLangganan ringkasan={ringkasan} />
                    {/* `kursi_dibayar` bernilai 1 sejak toko lahir, jadi toko yang belum
                        pernah membayar dulu berkata "1 dari 1 yang dibayar" (AV8). */}
                    <p data-kursi-langganan className="mt-4 text-sm text-slate-600 dark:text-slate-400">
                        Outlet berjualan: <span className="font-semibold text-slate-900 dark:text-white">{ringkasan.kursi_terpakai}</span>
                        {ringkasan.pernah_aktif ? (
                            <>
                                {" "}dari <span className="font-semibold text-slate-900 dark:text-white">{ringkasan.kursi_dibayar}</span> yang dibayar.
                            </>
                        ) : (
                            " — belum ada yang dibayar."
                        )}
                    </p>
                    {!konfirmasiOtomatis && (
                        <p className="mt-3 flex gap-2 rounded-xl bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                            <IconAlertTriangle size={16} className="mt-0.5 shrink-0" />
                            Pembayaran dikonfirmasi pengelola secara manual untuk sementara — biasanya dalam beberapa jam.
                        </p>
                    )}
                </section>

                {tagihan ? (
                    <TagihanTerbuka tagihan={tagihan} qris={qris} whatsapp={whatsapp} bolehMengatur={bolehMengatur} />
                ) : bolehMengatur ? (
                    <BuatTagihan
                        ringkasan={ringkasan}
                        pilihanBulan={pilihanBulan}
                        hargaPerOutlet={hargaPerOutlet}
                        hariProrata={hariProrata}
                    />
                ) : (
                    <p className={`${kartu} text-sm text-slate-600 dark:text-slate-400`}>
                        Langganan diatur pemilik toko.
                    </p>
                )}
                {errors?.tagihan && !bolehMengatur && <p className="text-sm text-danger-500">{errors.tagihan}</p>}

                <section className={kartu}>
                    <h2 className="flex items-center gap-2 text-base font-semibold text-slate-900 dark:text-white">
                        <IconBuildingStore size={18} />
                        Outlet yang ditagih
                    </h2>
                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Gudang pusat dan outlet yang tidak berjualan tidak ditagih.
                    </p>
                    <ul className="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                        {outletBerjualan.map((outlet) => (
                            <li key={outlet.id} className="flex items-center justify-between gap-3 py-3">
                                <div className="min-w-0">
                                    <p className="truncate font-medium text-slate-900 dark:text-white">{outlet.name}</p>
                                    <p className="text-xs text-slate-500">{outlet.code}</p>
                                </div>
                                {bolehMengatur && outletBerjualan.length > 1 && (
                                    <button
                                        type="button"
                                        onClick={() => berhentiJual(outlet)}
                                        className="shrink-0 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-300"
                                    >
                                        Berhenti jual
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                {riwayat.length > 0 && (
                    <section className={kartu}>
                        <h2 className="flex items-center gap-2 text-base font-semibold text-slate-900 dark:text-white">
                            <IconHistory size={18} />
                            Riwayat tagihan
                        </h2>
                        <ul className="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                            {riwayat.map((t) => (
                                <li key={t.id} className="flex items-start justify-between gap-3 py-3 text-sm">
                                    <div className="min-w-0">
                                        <p className="font-medium text-slate-900 dark:text-white">{rupiah(t.total_bayar)}</p>
                                        <p className="truncate text-xs text-slate-500">
                                            {t.nomor} ·{" "}
                                            {t.jenis === "perpanjang" ? `${t.kursi} outlet × ${t.bulan} bln` : `+${t.kursi} outlet`}
                                        </p>
                                    </div>
                                    <span
                                        className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold ${
                                            t.status === "lunas"
                                                ? "bg-success-100 text-success-700 dark:bg-success-950 dark:text-success-300"
                                                : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                                        }`}
                                    >
                                        {t.status === "lunas" ? `Lunas ${tanggalPendek(t.dibayar_pada)}` : t.status === "batal" ? "Dibatalkan" : "Kedaluwarsa"}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
