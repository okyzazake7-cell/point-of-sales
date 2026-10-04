/**
 * MEREK — kembaran `config/brand.php` untuk React.
 *
 * Satu-satunya tempat nama dan tautan merek ditulis di sisi peramban;
 * halaman membaca dari sini, tidak mengetiknya sendiri. Namanya dijaga
 * `tests/Feature/BrandTest.php` supaya tidak menyimpang dari sisi PHP.
 *
 * Alamat Aishii boleh ditimpa saat membangun (`VITE_AISHII_URL`) —
 * dibaca saat BUILD, jadi mengubahnya menuntut `npm run build` ulang.
 */
export const BRAND = {
    name: "Aishii POS",
    tagline: "Kasir toko dari keluarga Aishii",
    themeColor: "#752e8e",
    icon: "/images/icon-192.png",
    parentName: "Aishii",
    // Penerbit keluarga Aishii — nama yang sama dengan kaki halaman Aishii.
    entity: "Aishii ERP",
    parentUrl: (import.meta.env.VITE_AISHII_URL || "https://aishiierp.com").replace(/\/$/, ""),
    sourceUrl: "https://github.com/okyzazake7-cell/point-of-sales",
    upstream: {
        name: "Point of Sales",
        author: "Arya Dwi Putra",
        url: "https://github.com/aryadwiputra/point-of-sales",
        license: "MIT",
    },
};

/** Kalimat atribusi yang sama dengan halaman `/pos` di Aishii. */
export const ATRIBUSI = `${BRAND.name} dibangun di atas proyek open-source ${BRAND.upstream.name} karya ${BRAND.upstream.author} (lisensi ${BRAND.upstream.license}).`;
