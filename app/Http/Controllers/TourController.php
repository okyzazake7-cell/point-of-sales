<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TourController extends Controller
{
    /**
     * Kunci panduan yang boleh ditandai selesai. Dibaca dari daftar panduan
     * yang SAMA dengan yang dipakai React (`resources/js/Panduan/daftar.json`):
     * setiap halaman punya panduannya sendiri, dan halaman baru tidak perlu
     * didaftarkan dua kali — kunci di sini tidak bisa menyimpang darinya.
     *
     * @return list<string>
     */
    public static function tours(): array
    {
        static $kunci = null;

        if ($kunci === null) {
            $daftar = json_decode(
                file_get_contents(resource_path('js/Panduan/daftar.json')),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $kunci = [];
            foreach ($daftar as $panduan) {
                // Nilai teks = rujukan ke komponen lain (Tambah/Ubah berbagi formulir).
                foreach (is_array($panduan) ? $panduan : [] as $varian) {
                    $kunci[] = $varian['key'];
                }
            }
            $kunci = array_values(array_unique($kunci));
        }

        return $kunci;
    }

    public function complete(Request $request, string $tour): JsonResponse
    {
        abort_unless(in_array($tour, self::tours(), true), 404);

        /** @var User $user */
        $user = $request->user();

        $completed = $user->completed_tours ?? [];

        if (! in_array($tour, $completed, true)) {
            $user->update(['completed_tours' => array_values([...$completed, $tour])]);
        }

        return response()->json(['completed' => $user->completed_tours]);
    }

    public function reset(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->update(['completed_tours' => null]);

        return response()->json(['completed' => []]);
    }
}
