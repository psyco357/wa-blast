<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KendaraanModel as Kendaraan;
use App\Models\BroadcastLogModel as BroadcastLog;
use App\Jobs\ProcessTemplateBlastJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class BroadcastController extends Controller
{


    /**
     * POST /api/broadcast/send-mass
     * Kirim broadcast massal
     */
    public function sendMassBroadcast(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kendaraan_ids' => 'required|array|min:1',
            'kendaraan_ids.*' => 'exists:kendaraan,id',
            'media_template' => 'required|string',
            'sender_id' => 'nullable|string', // Untuk tracking siapa yang mengirim
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Ambil data kendaraan yang statusnya pending atau gagal
        $kendaraan = Kendaraan::whereIn('id', $request->kendaraan_ids)
            ->whereIn('status_broadcast', ['pending', 'gagal'])
            ->get();

        if ($kendaraan->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No eligible vehicles found (status must be pending or failed)',
                'data' => [
                    'total_selected' => count($request->kendaraan_ids),
                    'eligible' => 0
                ]
            ], 400);
        }

        $dispatched = 0;
        $failed = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($kendaraan as $vehicle) {
                try {
                    // Dispatch ke queue
                    ProcessTemplateBlastJob::dispatch(
                        $vehicle->no_telepon,
                        $vehicle->nama_pemilik,
                        $vehicle->nomor_polisi,
                        $request->media_template
                    )->onQueue('broadcast');

                    // Update status kendaraan
                    $vehicle->update([
                        'status_broadcast' => 'pending',
                        'tanggal_kirim' => now(),
                        'pesan_blast' => $this->generateMessage($vehicle, $request->media_template),
                        'keterangan_gagal' => null,
                    ]);

                    $dispatched++;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = [
                        'id' => $vehicle->id,
                        'nomor_polisi' => $vehicle->nomor_polisi,
                        'error' => $e->getMessage()
                    ];

                    $vehicle->update([
                        'status_broadcast' => 'gagal',
                        'keterangan_gagal' => $e->getMessage(),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Broadcast job dispatched successfully",
                'data' => [
                    'total_dispatched' => $dispatched,
                    'total_failed' => $failed,
                    'total_selected' => count($request->kendaraan_ids),
                    'errors' => $errors,
                    'queue' => 'broadcast'
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Mass broadcast failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process broadcast',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/broadcast/send-single/{id}
     * Kirim broadcast single
     */
    public function sendSingleBroadcast(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'media_template' => 'required|string',
            'force' => 'nullable|boolean', // Force send even if already sent
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $kendaraan = Kendaraan::find($id);

        if (!$kendaraan) {
            return response()->json([
                'success' => false,
                'message' => 'Vehicle not found'
            ], 404);
        }

        // Cek status
        if (!$request->force && $kendaraan->status_broadcast === 'terkirim') {
            return response()->json([
                'success' => false,
                'message' => 'Broadcast already sent to this vehicle',
                'data' => [
                    'status' => $kendaraan->status_broadcast,
                    'sent_at' => $kendaraan->tanggal_kirim
                ]
            ], 400);
        }

        try {
            // Dispatch job
            ProcessTemplateBlastJob::dispatch(
                $kendaraan->no_telepon,
                $kendaraan->nama_pemilik,
                $kendaraan->nomor_polisi,
                $request->media_template
            )->onQueue('broadcast');

            // Update status
            $kendaraan->update([
                'status_broadcast' => 'pending',
                'tanggal_kirim' => now(),
                'pesan_blast' => $this->generateMessage($kendaraan, $request->media_template),
                'keterangan_gagal' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Broadcast job dispatched successfully',
                'data' => [
                    'id' => $kendaraan->id,
                    'nomor_polisi' => $kendaraan->nomor_polisi,
                    'status' => $kendaraan->status_broadcast,
                    'queue' => 'broadcast'
                ]
            ]);
        } catch (\Exception $e) {
            $kendaraan->update([
                'status_broadcast' => 'gagal',
                'keterangan_gagal' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to dispatch broadcast job',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/broadcast/retry-failed
     * Kirim ulang broadcast yang gagal
     */
    public function retryFailedBroadcast(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kendaraan_ids' => 'required|array|min:1',
            'kendaraan_ids.*' => 'exists:kendaraan,id',
            'media_template' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Ambil hanya yang statusnya gagal
        $kendaraan = Kendaraan::whereIn('id', $request->kendaraan_ids)
            ->where('status_broadcast', 'gagal')
            ->get();

        if ($kendaraan->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No failed broadcasts found to retry'
            ], 400);
        }

        $dispatched = 0;
        $skipped = count($request->kendaraan_ids) - $kendaraan->count();

        foreach ($kendaraan as $vehicle) {
            ProcessTemplateBlastJob::dispatch(
                $vehicle->no_telepon,
                $vehicle->nama_pemilik,
                $vehicle->nomor_polisi,
                $request->media_template
            )->onQueue('broadcast');

            $vehicle->update([
                'status_broadcast' => 'pending',
                'keterangan_gagal' => null,
                'tanggal_kirim' => now(),
            ]);

            $dispatched++;
        }

        return response()->json([
            'success' => true,
            'message' => 'Retry jobs dispatched successfully',
            'data' => [
                'total_retried' => $dispatched,
                'total_skipped' => $skipped,
                'reason_skipped' => 'Status not failed'
            ]
        ]);
    }

    /**
     * GET /api/broadcast/logs
     * Lihat log broadcast (with filters)
     */
    public function getLogs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kendaraan_id' => 'nullable|exists:kendaraan,id',
            'status' => 'nullable|in:pending,terkirim,gagal',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $query = BroadcastLog::with('kendaraan');

        if ($request->has('kendaraan_id')) {
            $query->where('kendaraan_id', $request->kendaraan_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $limit = $request->get('limit', 50);
        $logs = $query->orderBy('created_at', 'desc')
            ->paginate($limit);

        return response()->json([
            'success' => true,
            'message' => 'Broadcast logs retrieved successfully',
            'data' => [
                'current_page' => $logs->currentPage(),
                'data' => $logs->items(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ]
        ]);
    }

    /**
     * GET /api/broadcast/status/{id}
     * Cek status broadcast kendaraan
     */
    public function checkStatus($id)
    {
        $kendaraan = Kendaraan::find($id);

        if (!$kendaraan) {
            return response()->json([
                'success' => false,
                'message' => 'Vehicle not found'
            ], 404);
        }

        // Ambil last log
        $lastLog = BroadcastLog::where('kendaraan_id', $id)
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Broadcast status retrieved successfully',
            'data' => [
                'vehicle' => [
                    'id' => $kendaraan->id,
                    'nomor_polisi' => $kendaraan->nomor_polisi,
                    'nama_pemilik' => $kendaraan->nama_pemilik,
                    'no_telepon' => $kendaraan->no_telepon,
                ],
                'broadcast_status' => $kendaraan->status_broadcast,
                'tanggal_kirim' => $kendaraan->tanggal_kirim,
                'keterangan_gagal' => $kendaraan->keterangan_gagal,
                'last_log' => $lastLog ? [
                    'status' => $lastLog->status,
                    'sent_at' => $lastLog->sent_at,
                    'created_at' => $lastLog->created_at,
                ] : null
            ]
        ]);
    }

    /**
     * GET /api/broadcast/stats
     * Statistik broadcast
     */
    public function getStats(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kode_wilayah' => 'nullable|string|max:20',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $query = Kendaraan::query();
        $logQuery = BroadcastLog::query();

        if ($request->has('kode_wilayah')) {
            $query->where('kode_wilayah', $request->kode_wilayah);
            $logQuery->whereHas('kendaraan', function ($q) use ($request) {
                $q->where('kode_wilayah', $request->kode_wilayah);
            });
        }

        if ($request->has('start_date')) {
            $logQuery->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $logQuery->whereDate('created_at', '<=', $request->end_date);
        }

        $stats = [
            'vehicles' => [
                'total' => $query->count(),
                'pending' => (clone $query)->where('status_broadcast', 'pending')->count(),
                'terkirim' => (clone $query)->where('status_broadcast', 'terkirim')->count(),
                'gagal' => (clone $query)->where('status_broadcast', 'gagal')->count(),
            ],
            'logs' => [
                'total' => $logQuery->count(),
                'success' => (clone $logQuery)->where('status', 'terkirim')->count(),
                'failed' => (clone $logQuery)->where('status', 'gagal')->count(),
            ],
            'success_rate' => 0,
        ];

        // Hitung success rate
        if ($stats['logs']['total'] > 0) {
            $stats['success_rate'] = round(
                ($stats['logs']['success'] / $stats['logs']['total']) * 100,
                2
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Broadcast statistics retrieved successfully',
            'data' => $stats
        ]);
    }

    /**
     * POST /api/broadcast/cancel/{id}
     * Batalkan broadcast yang masih pending
     */
    public function cancelBroadcast($id)
    {
        $kendaraan = Kendaraan::find($id);

        if (!$kendaraan) {
            return response()->json([
                'success' => false,
                'message' => 'Vehicle not found'
            ], 404);
        }

        if ($kendaraan->status_broadcast !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel broadcast with status: ' . $kendaraan->status_broadcast,
                'data' => [
                    'current_status' => $kendaraan->status_broadcast
                ]
            ], 400);
        }

        $kendaraan->update([
            'status_broadcast' => 'gagal',
            'keterangan_gagal' => 'Cancelled by user',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Broadcast cancelled successfully',
            'data' => [
                'id' => $kendaraan->id,
                'status' => $kendaraan->status_broadcast
            ]
        ]);
    }

    /**
     * Generate pesan broadcast (private method)
     */
    private function generateMessage($kendaraan, $template)
    {
        return str_replace(
            ['{nama}', '{no_pol}', '{tgl_pajak}', '{tagihan}'],
            [
                $kendaraan->nama_pemilik,
                $kendaraan->nomor_polisi,
                $kendaraan->tanggal_akhir_pajak->format('d/m/Y'),
                number_format($kendaraan->jumlah_tagihan, 0, ',', '.')
            ],
            $template
        );
    }
}
