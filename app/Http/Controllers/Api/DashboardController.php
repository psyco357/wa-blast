<?php
// app/Http/Controllers/API/DashboardController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KendaraanModel;
use App\Models\BroadcastLog;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function stats()
    {
        $stats = [
            'total_data' => KendaraanModel::count(),
            'data_terkirim' => KendaraanModel::where('status_broadcast', 'terkirim')->count(),
            'data_gagal' => KendaraanModel::where('status_broadcast', 'gagal')->count(),
            'data_pending' => KendaraanModel::where('status_broadcast', 'pending')->count(),
            'total_tagihan' => KendaraanModel::sum('jumlah_tagihan'),
            'persentase_terkirim' => $this->calculatePercentage(
                KendaraanModel::where('status_broadcast', 'terkirim')->count(),
                KendaraanModel::count()
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
