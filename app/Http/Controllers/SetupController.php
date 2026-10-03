<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\SetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public const BUSINESS_TYPES = [
        'food' => ['food', 'beverages', 'snacks', 'coffeeTea'],
        'retail' => ['general', 'electronics', 'homeSupplies', 'beauty'],
        'grocery' => ['staples', 'produce', 'meatSeafood', 'snacks'],
        'fashion' => ['clothing', 'shoes', 'bags', 'accessories'],
        'pharmacy' => ['otcMedicine', 'prescriptionMedicine', 'vitamins', 'medicalSupplies'],
        'services' => ['services', 'products', 'packages'],
    ];

    public function index(): Response
    {
        $primaryWarehouse = Warehouse::find(Setting::get('setup_warehouse_id'));

        return Inertia::render('Setup/Wizard', [
            'businessTypes' => array_map(
                fn (string $key, array $categories) => ['key' => $key, 'categories' => $categories],
                array_keys(self::BUSINESS_TYPES),
                self::BUSINESS_TYPES,
            ),
            'primaryWarehouse' => $primaryWarehouse?->only(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'store_name' => 'required|string|max:255',
            'store_address' => 'nullable|string|max:500',
            'store_phone' => 'nullable|string|max:50',
            'store_email' => 'nullable|email|max:255',
            'store_logo' => 'nullable|image|max:2048',
            'business_type' => 'required|string|in:'.implode(',', array_keys(self::BUSINESS_TYPES)),
            'categories' => 'required|array|min:1',
            'categories.*' => 'required|string|max:255',
            'user_name' => 'required|string|max:255',
            'user_email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'warehouse_id' => [
                'nullable',
                'integer',
                'exists:warehouses,id',
                function ($attribute, $value, $fail) {
                    if ($value && ! Warehouse::whereKey($value)->where('code', 'PUSAT')->exists()) {
                        $fail('Wizard setup hanya dapat memakai gudang PUSAT.');
                    }
                },
            ],
            'warehouse_code' => [
                'required',
                'string',
                'max:20',
                function ($attribute, $value, $fail) use ($request) {
                    $warehouseId = $request->input('warehouse_id');
                    $exists = DB::table('warehouses')
                        ->where('code', $value)
                        ->when($warehouseId, fn ($q) => $q->where('id', '!=', $warehouseId))
                        ->exists();
                    if ($exists) {
                        $fail('Kode gudang sudah digunakan.');
                    }
                },
            ],
            'warehouse_name' => 'required|string|max:255',
            'branches' => 'required|array|min:1|max:10',
            'branches.*.outlet_code' => [
                'required',
                'string',
                'max:20',
                'distinct',
                'unique:outlets,code',
            ],
            'branches.*.outlet_name' => 'required|string|max:100',
            'branches.*.warehouse_code' => [
                'required',
                'string',
                'max:20',
                'distinct',
                'unique:warehouses,code',
            ],
            'branches.*.warehouse_name' => 'required|string|max:100',
            'branches.*.address' => 'nullable|string|max:500',
            'branches.*.phone' => 'nullable|string|max:50',
        ]);

        app(SetupService::class)->run($validated);

        if ($request->file('store_logo')) {
            $path = $request->file('store_logo')->store('store', 'public');
            Setting::set('store_logo', $path, 'Logo toko');
        }

        return redirect()->route('login')->with('success', __('setup.completed'));
    }
}
