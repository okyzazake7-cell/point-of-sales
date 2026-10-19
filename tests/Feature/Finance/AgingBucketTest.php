<?php

namespace Tests\Feature\Finance;

use App\Models\Customer;
use App\Models\Payable;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Umur utang dan piutang. Carbon 3 menghitung `now()->diffInDays($lalu)`
 * NEGATIF, jadi setiap tagihan yang lewat jatuh tempo masuk "0–30 hari",
 * juga di rekap per pemasok: utang yang telat empat bulan tampak sama
 * dengan yang telat sehari.
 */
class AgingBucketTest extends TestCase
{
    use RefreshDatabase;

    public static function umur(): array
    {
        return [
            'telat 10 hari' => [10, '0-30'],
            'telat 45 hari' => [45, '31-60'],
            'telat 75 hari' => [75, '61-90'],
            'telat 120 hari' => [120, '90+'],
        ];
    }

    #[DataProvider('umur')]
    public function test_overdue_payable_and_receivable_fall_in_their_age_bucket(int $hari, string $kelompok): void
    {
        $supplier = Supplier::create(['name' => 'Pemasok Umur']);
        $payable = Payable::create([
            'supplier_id' => $supplier->id,
            'document_number' => 'PYB-UMUR',
            'total' => 100000,
            'paid' => 0,
            'due_date' => now()->subDays($hari)->toDateString(),
            'status' => 'unpaid',
        ]);
        $customer = Customer::create(['name' => 'Pelanggan Umur', 'no_telp' => '0811', 'address' => 'Jalan Umur']);
        $receivable = Receivable::create([
            'customer_id' => $customer->id,
            'invoice' => 'TRX-UMUR',
            'total' => 100000,
            'paid' => 0,
            'due_date' => now()->subDays($hari)->toDateString(),
            'status' => 'unpaid',
        ]);

        $this->assertSame($kelompok, $payable->fresh()->aging_bucket);
        $this->assertSame($kelompok, $receivable->fresh()->aging_bucket);
    }

    public function test_bill_not_yet_due_stays_current(): void
    {
        $supplier = Supplier::create(['name' => 'Pemasok Umur']);
        $payable = Payable::create([
            'supplier_id' => $supplier->id,
            'document_number' => 'PYB-BELUM',
            'total' => 100000,
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => 'unpaid',
        ]);

        $this->assertSame('current', $payable->fresh()->aging_bucket);
    }

    public function test_supplier_statement_groups_overdue_debt_by_age(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $admin->markEmailAsVerified();
        Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);

        $supplier = Supplier::create(['name' => 'Pemasok Rekap']);
        foreach ([3 => 'PYB-A', 45 => 'PYB-B', 100 => 'PYB-C'] as $hari => $nomor) {
            Payable::create([
                'supplier_id' => $supplier->id,
                'document_number' => $nomor,
                'total' => 50000,
                'due_date' => now()->subDays($hari)->toDateString(),
                'status' => 'unpaid',
            ]);
        }

        $response = $this->actingAs($admin)
            ->getJson(route('payables.supplier-statement', ['supplier_id' => $supplier->id]))
            ->assertOk();

        $kelompok = collect($response->json('payables'))->pluck('aging_bucket', 'document_number')->sortKeys()->all();
        $this->assertSame(['PYB-A' => '0-30', 'PYB-B' => '31-60', 'PYB-C' => '90+'], $kelompok);
    }
}
