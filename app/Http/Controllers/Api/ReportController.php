<?php
// app/Http/Controllers/API/ReportController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KendaraanModel;
use App\Models\BroadcastLog;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function exportExcel(Request $request)
    {
        $query = KendaraanModel::with('broadcastLogs');

        // Apply filters
        if ($request->has('status')) {
            $query->where('status_broadcast', $request->status);
        }

        if ($request->has('start_date')) {
            $query->where('tanggal_akhir_pajak', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('tanggal_akhir_pajak', '<=', $request->end_date);
        }

        $vehicles = $query->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set headers
        $headers = [
            'No',
            'Kode Wilayah',
            'Jenis Roda',
            'Nomor Polisi',
            'Nama Pemilik',
            'Tanggal Pajak',
            'No Telepon',
            'Jumlah Tagihan',
            'Status Broadcast',
            'Tanggal Kirim',
            'Status Pengiriman',
            'Keterangan'
        ];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(chr(65 + $index) . '1', $header);
        }

        // Set data
        $row = 2;
        foreach ($vehicles as $index => $vehicle) {
            $lastBroadcast = $vehicle->broadcastLogs()->latest()->first();

            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, $vehicle->kode_wilayah);
            $sheet->setCellValue('C' . $row, $vehicle->jenis_roda);
            $sheet->setCellValue('D' . $row, $vehicle->nomor_polisi);
            $sheet->setCellValue('E' . $row, $vehicle->nama_pemilik);
            $sheet->setCellValue('F' . $row, $vehicle->tanggal_akhir_pajak->format('Y-m-d'));
            $sheet->setCellValue('G' . $row, $vehicle->no_telepon);
            $sheet->setCellValue('H' . $row, $vehicle->jumlah_tagihan);
            $sheet->setCellValue('I' . $row, $vehicle->status_broadcast);
            $sheet->setCellValue('J' . $row, $vehicle->tanggal_kirim ? $vehicle->tanggal_kirim->format('Y-m-d H:i:s') : '-');
            $sheet->setCellValue('K' . $row, $lastBroadcast ? $lastBroadcast->status : '-');
            $sheet->setCellValue('L' . $row, $vehicle->keterangan_gagal ?? '-');

            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'report_blast_' . date('Y-m-d_His') . '.xlsx';

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function getReportData(Request $request)
    {
        $query = KendaraanModel::query();

        if ($request->has('status')) {
            $query->where('status_broadcast', $request->status);
        }

        $summary = [
            'total_data' => $query->count(),
            'total_tagihan' => $query->sum('jumlah_tagihan'),
            'terkirim' => (clone $query)->where('status_broadcast', 'terkirim')->count(),
            'pending' => (clone $query)->where('status_broadcast', 'pending')->count(),
            'gagal' => (clone $query)->where('status_broadcast', 'gagal')->count(),
        ];

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data' => $query->paginate(20)
        ]);
    }


    // pengambilan data berdasarkan filter tanggal
    public function getReportByDate(Request $request)
    {
        $query = KendaraanModel::query();

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('tanggal_akhir_pajak', [$request->start_date, $request->end_date]);
        }

        $statuses = [
            'belum_dikirim',
            'antrian',
            'sedang_dikirim',
            'terkirim',
            'dibaca',
            'gagal'
        ];

        $counts = $query
            ->selectRaw('status_broadcast, COUNT(*) as total')
            ->groupBy('status_broadcast')
            ->pluck('total', 'status_broadcast');

        $data = collect($statuses)->map(function ($status) use ($counts) {
            return [
                'status_broadcast' => $status,
                'total' => $counts[$status] ?? 0,
            ];
        });

        // Tambahkan total semua status
        $data->push([
            'status_broadcast' => 'semua_status',
            'total' => $data->sum('total')
        ]);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    // download excel berdasarkan filter dan jumlah yang dipilih
    public function exportFilteredExcel(Request $request)
    {
        $query = KendaraanModel::query();

        if ($request->has('status') && $request->status != 'semua_status') {
            $query->where('status_broadcast', $request->status);
        }


        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('tanggal_akhir_pajak', [$request->start_date, $request->end_date]);
        }

        $vehicles = $query->get();
        // dd([
        //     'count' => $vehicles->count(),
        //     'request' => $request->all(),
        // ]); 
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $headers = [
            'No',
            'Kode Wilayah',
            'Jenis Roda',
            'Nomor Polisi',
            'Nama Pemilik',
            'Tanggal Pajak',
            'No Telepon',
            'Jumlah Tagihan',
            'Status Broadcast',
            'Tanggal Kirim',
            'Status Pengiriman',
            'Keterangan'
        ];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(chr(65 + $index) . '1', $header);
        }
        $row = 2;
        foreach ($vehicles as $index => $vehicle) {
            $lastBroadcast = $vehicle->broadcastLogs()->latest()->first();
            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, $vehicle->kode_wilayah);
            $sheet->setCellValue('C' . $row, $vehicle->jenis_roda);
            $sheet->setCellValue('D' . $row, $vehicle->nomor_polisi);
            $sheet->setCellValue('E' . $row, $vehicle->nama_pemilik);
            $sheet->setCellValue('F' . $row, $vehicle->tanggal_akhir_pajak->format('Y-m-d'));
            $sheet->setCellValue('G' . $row, $vehicle->no_telepon);
            $sheet->setCellValue('H' . $row, $vehicle->jumlah_tagihan);
            $sheet->setCellValue('I' . $row, $vehicle->status_broadcast);
            $sheet->setCellValue('J' . $row, $vehicle->tanggal_kirim ? $vehicle->tanggal_kirim->format('Y-m-d H:i:s') : '-');
            $sheet->setCellValue('K' . $row, $lastBroadcast ? $lastBroadcast->status : '-');
            $sheet->setCellValue('L' . $row, $vehicle->keterangan_gagal ?? '-');
            $row++;
        }
        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $writer = new Xlsx($spreadsheet);
        $filename = 'filtered_report_blast_' . date('Y-m-d_His') . '.xlsx';
        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
