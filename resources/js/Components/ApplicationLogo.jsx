import { BRAND } from "@/Utils/brand";

/**
 * Tanda merek Aishii POS. Dulu logo Laravel bawaan Breeze — dan ia tampil
 * di halaman yang dibuka PELANGGAN toko (struk bersama, atur ulang sandi),
 * tempat paling buruk untuk logo kerangka kerja.
 */
export default function ApplicationLogo({ className = "", ...props }) {
    return (
        <img
            src={BRAND.icon}
            alt={BRAND.name}
            width="192"
            height="192"
            {...props}
            className={`rounded-xl object-contain ${className}`}
        />
    );
}
