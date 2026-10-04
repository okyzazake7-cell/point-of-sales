import DashboardLayout from '@/Layouts/DashboardLayout';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import { Head } from '@inertiajs/react';

/*
 * Profil tinggal di TATA LETAK DASHBOARD, sama dengan halaman lain.
 *
 * Ia satu-satunya halaman yang masih memakai tata letak bawaan Breeze — bilah
 * abu-abu ber-logo Laravel, judul "Profile", tanpa menu samping — jadi orang
 * yang mengetuk "Profil" dari menu akun mendarat di aplikasi yang terlihat
 * lain, tanpa jalan pulang selain tombol kembali peramban.
 */
const kartu = 'p-4 sm:p-8 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800';

export default function Edit() {
    return (
        <>
            <Head title="Profil" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Profil</h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Nama, surel, foto, dan kata sandi akunmu.
                    </p>
                </div>

                <div className={kartu}>
                    <UpdateProfileInformationForm className="max-w-xl" />
                </div>

                <div className={kartu}>
                    <UpdatePasswordForm className="max-w-xl" />
                </div>

                <div className={kartu}>
                    <DeleteUserForm className="max-w-xl" />
                </div>
            </div>
        </>
    );
}

Edit.layout = (page) => <DashboardLayout children={page} />;
