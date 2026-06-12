<?php
// app/Http/Controllers/Api/ImportController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessKendaraanImportJob;
use App\Models\ImportHistoryModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ImportController extends Controller
{
    /**
     * Import data dari file Excel
     */
    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240', // max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $path = $request->file('file')->store('imports', 'local');
            $importHistory = ImportHistoryModel::create([
                'file_name' => $request->file('file')->getClientOriginalName(),
                'stored_path' => $path,
                'mode' => 'spreadsheet',
                'queue_name' => 'imports',
                'status' => 'queued',
            ]);

            $job = new ProcessKendaraanImportJob(
                $path,
                'spreadsheet',
                $request->file('file')->getClientOriginalName(),
                'local',
                $importHistory->id,
            );

            dispatch($job);

            return response()->json([
                'success' => true,
                'message' => 'Import data masuk queue',
                'data' => [
                    'import_id' => $importHistory->id,
                    'status' => $importHistory->status,
                    'queue' => $job->queue ?? config('queue.connections.' . config('queue.default') . '.queue', 'default'),
                    'file' => $request->file('file')->getClientOriginalName(),
                    'path' => $path,
                    'success_count' => 0,
                    'fail_count' => 0,
                ]
            ], 202);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal import data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download template Excel
     */
    public function downloadTemplate()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Header
        $headers = [
            'Kode Wilayah',
            'Jenis Roda',
            'Nomor Polisi',
            'Nama Pemilik',
            'Tanggal Akhir Pajak',
            'No Telepon',
            'Jumlah Tagihan'
        ];

        $sheet->fromArray($headers, null, 'A1');

        // Contoh data
        $sheet->fromArray([
            [
                '11800',
                'r2',
                'K 2978 EG',
                'DIDIK AKLAMIMASA',
                '2026-01-07',
                '08123456789',
                '12600000'
            ],
            [
                '11801',
                'r4',
                'B 1234 CD',
                'BUDI SANTOSO',
                '2026-02-15',
                '08123456790',
                '25000000'
            ]
        ], null, 'A2');

        // Header bold
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);

        // Auto size kolom
        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        $fileName = 'template_import_vehicle.xlsx';

        return response()->streamDownload(
            function () use ($writer) {
                $writer->save('php://output');
            },
            $fileName,
            [
                'Content-Type' =>
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        );
    }

    /**
     * Import dari CSV (alternatif tanpa package Excel)
     */
    public function importCsv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $path = $request->file('file')->store('imports', 'local');
        $importHistory = ImportHistoryModel::create([
            'file_name' => $request->file('file')->getClientOriginalName(),
            'stored_path' => $path,
            'mode' => 'csv',
            'queue_name' => 'imports',
            'status' => 'queued',
        ]);

        $job = new ProcessKendaraanImportJob(
            $path,
            'csv',
            $request->file('file')->getClientOriginalName(),
            'local',
            $importHistory->id,
        );

        dispatch($job);

        return response()->json([
            'success' => true,
            'message' => 'Import CSV masuk queue',
            'data' => [
                'import_id' => $importHistory->id,
                'status' => $importHistory->status,
                'queue' => $job->queue ?? config('queue.connections.' . config('queue.default') . '.queue', 'default'),
                'file' => $request->file('file')->getClientOriginalName(),
                'path' => $path,
                'success_count' => 0,
                'fail_count' => 0,
            ]
        ], 202);
    }

    public function importStatus(int $id)
    {
        $importHistory = ImportHistoryModel::find($id);

        if ($importHistory === null) {
            return response()->json([
                'success' => false,
                'message' => 'Data import tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Status import ditemukan.',
            'data' => [
                'import_id' => $importHistory->id,
                'status' => $importHistory->status,
                'queue' => $importHistory->queue_name,
                'file' => $importHistory->file_name,
                'mode' => $importHistory->mode,
                'success_count' => $importHistory->success_count,
                'fail_count' => $importHistory->fail_count,
                'errors' => $importHistory->errors ?? [],
                'started_at' => $importHistory->started_at,
                'finished_at' => $importHistory->finished_at,
            ],
        ]);
    }
}
