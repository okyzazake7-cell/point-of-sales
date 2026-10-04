#!/usr/bin/env bash
# Uji asap Aishii POS mode banyak toko (AS10, docs/deploy-pos-aishiierp.md).
# Dijalankan .github/workflows/deploy.yml sesudah tiap terbit, dan bisa
# dijalankan pemilik dari komputer mana pun:
#
#   bash scripts/uji-asap-pos.sh https://pos.aishiierp.com
#
# Kenapa bukan cuma "halaman depan menjawab 200": instalasi HULU satu toko
# juga menjawab 200 di sana. Tiap baris di bawah memeriksa sesuatu yang
# HANYA benar bila server ini benar-benar mode banyak toko yang siap jualan —
# alasannya ditulis di kolom terakhir dan ikut tercetak bila meleset.
# Keluar 1 bila satu pun meleset; seluruhnya tetap diperiksa supaya satu
# kali jalan menyebut semua yang rusak.
set -u

DASAR="${1:-https://pos.aishiierp.com}"
DASAR="${DASAR%/}"
gagal=0

# cek <jalur> <kode harapan> <potongan isi wajib atau ''> <kenapa>
cek() {
    local jalur="$1" harap="$2" potongan="$3" kenapa="$4" keluaran kode isi
    # Tanpa --location: pengalihan ke /setup (tanda instalasi satu toko)
    # harus terbaca sebagai 302, bukan diikuti sampai 200.
    if ! keluaran=$(curl --silent --show-error --max-time 20 -w '\n%{http_code}' "$DASAR$jalur" 2>&1); then
        echo "❌ $jalur tidak terjangkau — $kenapa"
        echo "   $keluaran" | head -2
        gagal=1
        return
    fi
    kode="${keluaran##*$'\n'}"
    isi="${keluaran%$'\n'*}"
    if [ "$kode" != "$harap" ]; then
        echo "❌ $jalur menjawab HTTP $kode, harapan $harap — $kenapa"
        gagal=1
    elif [ -n "$potongan" ] && ! grep -qF -- "$potongan" <<<"$isi"; then
        echo "❌ $jalur tidak memuat \"$potongan\" — $kenapa"
        gagal=1
    else
        echo "✅ $jalur (HTTP $kode) — $kenapa"
    fi
}

cek /                200 ''                   'halaman depan'
cek /login           200 ''                   'pintu masuk kasir dan pemilik'
cek /daftar          200 ''                   'pendaftaran toko — rute ini HANYA ada bila POS_MULTI_TOKO=true'
cek /pengelola/masuk 200 ''                   'pintu pengelola (tandai lunas manual)'
cek /api/harga       200 '"harga_per_outlet"' 'harga yang dibaca halaman /pos Aishii'
cek /api/v1/products 400 'X-Toko'             'API menuntut kepala X-Toko — instalasi satu toko menjawab 401 di sini'
cek /up              200 ''                   'kesehatan Laravel'

# Halaman /pos Aishii membaca harga lintas asal. Tanpa kepala CORS, peramban
# menolak jawabannya dan /pos diam tanpa harga — gejala yang tidak terlihat
# dari curl biasa.
cors=$(curl --silent --max-time 20 -D - -o /dev/null -H 'Origin: https://aishiierp.com' "$DASAR/api/harga" 2>/dev/null \
    | tr -d '\r' | grep -i '^access-control-allow-origin:' || true)
if [ -n "$cors" ]; then
    echo "✅ /api/harga mengizinkan https://aishiierp.com — ${cors}"
else
    echo "❌ /api/harga tanpa Access-Control-Allow-Origin — /pos Aishii tidak akan bisa membaca harganya"
    gagal=1
fi

if [ "$gagal" = 0 ]; then
    echo "✅ Uji asap $DASAR lulus"
else
    echo "❌ Uji asap $DASAR GAGAL"
fi
exit "$gagal"
