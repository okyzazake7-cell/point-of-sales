import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';

// Dipakai halaman yang dibuka PELANGGAN toko (struk bersama) dan atur ulang
// sandi — jadi ia berwujud keluarga Aishii, bukan kerangka abu-abu Breeze.
export default function Guest({ children }) {
    return (
        <div className="min-h-screen flex flex-col sm:justify-center items-center px-4 pt-6 sm:pt-0 bg-slate-50 dark:bg-slate-950">
            <div>
                <Link href="/">
                    <ApplicationLogo className="w-16 h-16" />
                </Link>
            </div>

            <div className="w-full sm:max-w-md mt-6 px-6 py-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 overflow-hidden rounded-2xl">
                {children}
            </div>
        </div>
    );
}
