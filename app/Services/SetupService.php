<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Isi wizard penyiapan awal toko: pemilik super-admin, gudang PUSAT, cabang
 * berjualan + gudangnya, kategori, dan profil toko.
 *
 * Dipindah apa adanya dari SetupController::store supaya pendaftaran Aishii
 * POS (mode banyak toko) menjalankan PERSIS langkah yang sama di dalam basis
 * data toko barunya — logika yang disalin ke dua tempat akan menyimpang di
 * salah satunya.
 */
class SetupService
{
    /**
     * @param  array<string, mixed>  $validated  bentuk yang divalidasi SetupController::store
     */
    public function run(array $validated): User
    {
        return DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['user_name'],
                'email' => $validated['user_email'],
                'password' => Hash::make($validated['password']),
            ]);
            $user->assignRole('super-admin');
            $user->markEmailAsVerified();

            $pusat = Outlet::firstOrCreate(
                ['code' => 'PUSAT'],
                [
                    'name' => 'Gudang Pusat',
                    'is_active' => true,
                    'is_sales_enabled' => false,
                ],
            );
            $pusat->update(['is_active' => true, 'is_sales_enabled' => false]);

            if (! empty($validated['warehouse_id'])) {
                Warehouse::where('id', $validated['warehouse_id'])->update([
                    'code' => $validated['warehouse_code'],
                    'name' => $validated['warehouse_name'],
                    'outlet_id' => $pusat->id,
                    'type' => 'main',
                    'is_active' => true,
                ]);
                Setting::set('setup_warehouse_id', $validated['warehouse_id']);
            } else {
                $warehouse = Warehouse::create([
                    'outlet_id' => $pusat->id,
                    'code' => $validated['warehouse_code'],
                    'name' => $validated['warehouse_name'],
                    'type' => 'main',
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
                Setting::set('setup_warehouse_id', $warehouse->id);
            }

            $user->outlets()->syncWithoutDetaching([
                $pusat->id => ['is_default' => true],
            ]);

            foreach ($validated['branches'] as $i => $branch) {
                $branchOutlet = Outlet::create([
                    'code' => strtoupper($branch['outlet_code']),
                    'name' => $branch['outlet_name'],
                    'is_active' => true,
                    'is_sales_enabled' => true,
                    'address' => $branch['address'] ?? null,
                    'phone' => $branch['phone'] ?? null,
                ]);

                Warehouse::create([
                    'outlet_id' => $branchOutlet->id,
                    'code' => strtoupper($branch['warehouse_code']),
                    'name' => $branch['warehouse_name'],
                    'type' => 'branch',
                    'is_active' => true,
                    'sort_order' => $i + 1,
                ]);

                $user->outlets()->syncWithoutDetaching([
                    $branchOutlet->id => ['is_default' => false],
                ]);
            }

            foreach (array_unique($validated['categories']) as $name) {
                Category::create(['name' => $name]);
            }

            foreach ([
                'store_name' => $validated['store_name'],
                'store_address' => $validated['store_address'] ?? '',
                'store_phone' => $validated['store_phone'] ?? '',
                'store_email' => $validated['store_email'] ?? '',
                'store_business_type' => $validated['business_type'],
            ] as $key => $value) {
                Setting::set($key, $value);
            }
            Setting::set('app_setup_completed', true, 'Penanda wizard setup awal sudah diselesaikan');

            return $user;
        });
    }
}
