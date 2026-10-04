<?php

namespace App\Langganan;

use App\Models\Outlet;
use App\Models\Pusat\LogPengelola;
use App\Models\Pusat\Pengelola;
use App\Models\Pusat\Tagihan;
use App\Models\Pusat\Toko;
use App\Penyewaan\Penyewaan;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Langganan Aishii POS per toko (AS8/AS9, dokumen 24 §5–§7).
 *
 * Satu-satunya tempat yang mengubah `toko.aktif_sampai` dan
 * `toko.kursi_outlet` — pembayaran otomatis, tandai lunas pengelola, dan
 * uji semuanya lewat `terapkanLunas`, sehingga aturan perpanjangannya tidak
 * bisa menyimpang di salah satu jalan.
 */
class Langganan
{
    public function __construct(
        private readonly Penyewaan $penyewaan,
        private readonly BukuTagihanAishii $buku,
    ) {}

    /** Belum pernah membayar, atau masa aktifnya sudah lewat — tanpa tenggang. */
    public function terkunci(Toko $toko, ?CarbonInterface $sekarang = null): bool
    {
        return ! $toko->aktif_sampai || $toko->aktif_sampai->lessThanOrEqualTo($sekarang ?? now());
    }

    /** Outlet yang menghabiskan kursi: aktif DAN berjualan. Gudang PUSAT gratis. */
    public function kursiTerpakai(Toko $toko): int
    {
        return $this->penyewaan->denganToko($toko, fn () => Outlet::query()
            ->where('is_active', true)
            ->where('is_sales_enabled', true)
            ->count());
    }

    /** @return array<string, mixed> bentuk yang dibaca layar (prop `langganan`). */
    public function ringkasan(Toko $toko): array
    {
        $sekarang = now();
        $terkunci = $this->terkunci($toko, $sekarang);
        $sisaHari = Harga::sisaHari($toko->aktif_sampai, $sekarang);

        return [
            'terkunci' => $terkunci,
            'pernah_aktif' => $toko->aktif_sampai !== null,
            'aktif_sampai' => $toko->aktif_sampai?->toIso8601String(),
            'sisa_hari' => $sisaHari,
            'perlu_diingatkan' => ! $terkunci && $sisaHari <= (int) config('langganan.pengingat_hari'),
            'kursi_dibayar' => (int) $toko->kursi_outlet,
            'kursi_terpakai' => $this->kursiTerpakai($toko),
            'harga_per_outlet' => Harga::perOutlet(),
        ];
    }

    public function tagihanTerbuka(Toko $toko): ?Tagihan
    {
        return Tagihan::query()
            ->where('toko_id', $toko->getKey())
            ->menunggu()
            ->where('berlaku_sampai', '>', now())
            ->latest('id')
            ->first();
    }

    public function buatPerpanjang(Toko $toko, int $bulan, int $kursi): Tagihan
    {
        if (! in_array($bulan, config('langganan.pilihan_bulan'), true)) {
            throw ValidationException::withMessages(['bulan' => 'Pilih 1, 3, 6, atau 12 bulan.']);
        }

        $minimal = max(1, $this->kursiTerpakai($toko));
        if ($kursi < $minimal) {
            throw ValidationException::withMessages([
                'kursi' => "Ada {$minimal} outlet yang sedang berjualan. Matikan penjualan outlet yang tidak dipakai dulu, atau bayar untuk {$minimal} outlet.",
            ]);
        }

        $this->tolakBilaAdaTagihanTerbuka($toko);

        return $this->terbitkan($toko, 'perpanjang', $kursi, $bulan, null, Harga::perpanjang($kursi, $bulan));
    }

    public function buatTambahOutlet(Toko $toko, int $tambahan): Tagihan
    {
        if ($this->terkunci($toko)) {
            throw ValidationException::withMessages([
                'kursi' => 'Langganan sedang tidak aktif — perpanjang dulu, lalu jumlah outletnya ikut dipilih di sana.',
            ]);
        }
        if ($tambahan < 1 || $tambahan > 50) {
            throw ValidationException::withMessages(['kursi' => 'Tambah 1 sampai 50 outlet sekaligus.']);
        }

        $this->tolakBilaAdaTagihanTerbuka($toko);
        $sisaHari = Harga::sisaHari($toko->aktif_sampai, now());

        return $this->terbitkan($toko, 'tambah_outlet', $tambahan, 0, $sisaHari, Harga::tambahOutlet($tambahan, $sisaHari));
    }

    /**
     * Pembatalan atas permintaan pemilik toko ("Ganti tagihan"). Tagihan yang
     * nominalnya dipegang buku tagihan Aishii hanya batal SESUDAH buku itu
     * mengiyakan: membatalkan di sini saja lalu pembayarannya masuk di sana
     * berarti uang yang tidak pernah menjadi langganan.
     */
    public function batalkan(Tagihan $tagihan): Tagihan
    {
        if ($tagihan->status !== 'menunggu') {
            return $tagihan;
        }

        if ($tagihan->pencocok === 'aishii') {
            $hasil = $this->buku->batalkan($tagihan->nomor);

            if ($hasil === 'sudah_dibayar') {
                return $this->terapkanLunas($tagihan, 'otomatis');
            }
            // 'kedaluwarsa' di sana juga berarti tidak ada pembayaran yang
            // bisa dicocokkan lagi dengannya — sama amannya dengan batal.
            if (! in_array($hasil, ['batal', 'tidak_ada', 'kedaluwarsa'], true)) {
                throw ValidationException::withMessages([
                    'tagihan' => 'Tagihan belum bisa dibatalkan karena konfirmasi pembayaran sedang tidak terjangkau. Coba lagi sebentar — dan jangan bayar tagihan ini bila Anda ingin menggantinya.',
                ]);
            }
        }

        $tagihan->forceFill(['status' => 'batal'])->save();

        return $tagihan;
    }

    /**
     * Menerapkan pembayaran: perpanjang = `max(aktif_sampai, sekarang) + N
     * bulan kalender` dan kursi menjadi yang dibayar (cara Aishii Bazar,
     * `greatest(berakhir, now())`); tambah outlet = kursi bertambah, tanggal
     * tetap. Aman dipanggil dua kali — yang kedua tidak mengubah apa pun.
     */
    public function terapkanLunas(
        Tagihan $tagihan,
        string $lewat,
        ?Pengelola $pengelola = null,
        ?string $alasan = null,
        ?CarbonInterface $dibayarPada = null,
    ): Tagihan {
        return DB::connection($this->penyewaan->koneksiPusat())->transaction(function () use ($tagihan, $lewat, $pengelola, $alasan, $dibayarPada) {
            $t = Tagihan::query()->lockForUpdate()->findOrFail($tagihan->getKey());
            if ($t->status === 'lunas') {
                return $t;
            }

            $toko = Toko::query()->lockForUpdate()->findOrFail($t->toko_id);
            $sekarang = now();

            if ($t->jenis === 'perpanjang') {
                $dasar = $toko->aktif_sampai && $toko->aktif_sampai->greaterThan($sekarang)
                    ? $toko->aktif_sampai->copy()
                    : $sekarang->copy();
                $toko->aktif_sampai = $dasar->addMonthsNoOverflow($t->bulan);
                $toko->kursi_outlet = $t->kursi;
            } else {
                $toko->kursi_outlet = (int) $toko->kursi_outlet + $t->kursi;
            }
            $toko->save();

            $t->forceFill([
                'status' => 'lunas',
                'dibayar_pada' => $dibayarPada ?? $sekarang,
                'dibayar_lewat' => $lewat,
                'pengelola_id' => $pengelola?->getKey(),
                'alasan' => $alasan,
                'periode_sampai' => $toko->aktif_sampai,
            ])->save();

            if ($pengelola) {
                LogPengelola::query()->create([
                    'pengelola_id' => $pengelola->getKey(),
                    'tindakan' => 'tandai lunas tagihan '.$t->nomor,
                    'toko_id' => $toko->getKey(),
                    'tagihan_id' => $t->getKey(),
                    'alasan' => (string) $alasan,
                ]);
            }

            return $t;
        });
    }

    /**
     * Menjemput status tagihan dari buku tagihan Aishii (penjadwal tiap menit
     * dan tombol "Periksa pembayaran"). Tagihan yang baru kedaluwarsa di sini
     * ikut ditanyakan seminggu: pembayaran menit terakhir yang dicatat Aishii
     * sementara server ini tak bisa menjangkaunya tidak boleh hilang.
     *
     * @return int jumlah tagihan yang baru lunas
     */
    public function periksaPembayaran(?Toko $toko = null): int
    {
        $calon = Tagihan::query()
            ->where('pencocok', 'aishii')
            ->where(fn ($q) => $q->where('status', 'menunggu')
                ->orWhere(fn ($q) => $q->where('status', 'kedaluwarsa')->where('berlaku_sampai', '>', now()->subDays(7))))
            ->when($toko, fn ($q) => $q->where('toko_id', $toko->getKey()))
            ->get();

        $lunas = 0;
        $status = $calon->isEmpty() ? [] : $this->buku->status($calon->pluck('nomor')->all());

        if ($status !== null) {
            foreach ($calon as $t) {
                $s = $status[$t->nomor] ?? null;
                if (($s['status'] ?? null) === 'dibayar') {
                    $this->terapkanLunas($t, 'otomatis', dibayarPada: isset($s['dibayar_pada']) ? Carbon::parse($s['dibayar_pada']) : null);
                    $lunas++;
                }
            }
        }

        // Kedaluwarsa menurut jam — HANYA sesudah status jauhnya terbaca,
        // atau untuk tagihan manual yang memang tidak punya status jauh.
        Tagihan::query()
            ->where('status', 'menunggu')
            ->where('berlaku_sampai', '<=', now())
            ->when($toko, fn ($q) => $q->where('toko_id', $toko->getKey()))
            ->when($status === null, fn ($q) => $q->where('pencocok', 'manual'))
            ->update(['status' => 'kedaluwarsa', 'updated_at' => now()]);

        return $lunas;
    }

    private function tolakBilaAdaTagihanTerbuka(Toko $toko): void
    {
        if ($this->tagihanTerbuka($toko)) {
            throw ValidationException::withMessages([
                'tagihan' => 'Masih ada tagihan yang menunggu pembayaran. Bayar tagihan itu, atau batalkan dulu bila ingin menggantinya.',
            ]);
        }
    }

    private function terbitkan(Toko $toko, string $jenis, int $kursi, int $bulan, ?int $hariProrata, int $nominal): Tagihan
    {
        $nomor = 'POS-'.$toko->getKey().'-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        $berlakuJam = (int) config('langganan.tagihan_berlaku_jam');
        $kode = $this->buku->buat($nomor, $nominal, $berlakuJam);

        return Tagihan::query()->create([
            'toko_id' => $toko->getKey(),
            'nomor' => $nomor,
            'jenis' => $jenis,
            'kursi' => $kursi,
            'bulan' => $bulan,
            'hari_prorata' => $hariProrata,
            'nominal_dasar' => $nominal,
            // Tanpa buku tagihan: nominal BULAT, konfirmasi manual — tidak
            // pernah kode pilihan sendiri (lihat BukuTagihanAishii).
            'kode_unik' => $kode['kode_unik'] ?? null,
            'total_bayar' => $kode['total_bayar'] ?? $nominal,
            'pencocok' => $kode ? 'aishii' : 'manual',
            'status' => 'menunggu',
            'berlaku_sampai' => now()->addHours($berlakuJam),
        ]);
    }
}
