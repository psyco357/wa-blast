<?php
// app/Http/Controllers/API/DashboardController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KendaraanModel;
use App\Models\BroadcastLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats()
    {
        $statuses = [
            'belum_dikirim',
            'antrian',
            'sedang_dikirim',
            'terkirim',
            'dibaca',
            'gagal'
        ];

        // Satu query untuk semua status counts
        $statusCounts = KendaraanModel::selectRaw('status_broadcast, count(*) as total')
            ->whereIn('status_broadcast', $statuses)
            ->groupBy('status_broadcast')
            ->pluck('total', 'status_broadcast')
            ->toArray();

        // Satu query untuk total data dan total tagihan
        $totals = KendaraanModel::selectRaw('count(*) as total_data, sum(jumlah_tagihan) as total_tagihan')
            ->first();

        // Mengisi default 0 untuk status yang tidak ada datanya
        $stats = [
            'total_data' => $totals->total_data,
            'data_terkirim' => $statusCounts['terkirim'] ?? 0,
            'data_gagal' => $statusCounts['gagal'] ?? 0,
            'data_antrian' => $statusCounts['antrian'] ?? 0,
            'data_sedang_dikirim' => $statusCounts['sedang_dikirim'] ?? 0,
            'data_dibaca' => $statusCounts['dibaca'] ?? 0,
            'data_belum_dikirim' => $statusCounts['belum_dikirim'] ?? 0,
            'total_tagihan' => (float) $totals->total_tagihan,
            'persentase_terkirim' => $this->calculatePercentage(
                $statusCounts['terkirim'] ?? 0,
                $totals->total_data
            )
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    private function calculatePercentage($partial, $total)
    {
        if ($total == 0) return 0;
        return round(($partial / $total) * 100, 2);
    }
}
