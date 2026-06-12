<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KendaraanModel as Kendaraan;
use App\Models\BroadcastLogModel as BroadcastLog;
use App\Models\WhatsappMessageModel;
use App\Models\WhatshapDataModel;
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
        // dd($request->all());
        // Log::info('SEND MASS', $request->all());
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
        // Ambil data kendaraan yang belum pernah dikirim atau pernah gagal.
        $kendaraan = Kendaraan::whereIn('id', $request->kendaraan_ids)
            ->get();
        // dd($kendaraan);

        // die;
        if ($kendaraan->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No eligible vehicles found (status must be belum_dikirim or gagal)',
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
                    )->onQueue('broadcasts');

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
                    'queue' => 'broadcasts'
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
            )->onQueue('broadcasts');

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
                    'queue' => 'broadcasts'
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


    public function statusUpdateCallback(Request $request)
    {
        $payload = $request->all();
        Log::info('NusaSMS Callback', $payload);

        // Cek tipe callback
        if ($payload['type'] !== 'status') {
            return response()->json(['success' => true]);
        }

        $statusData = $payload['status'];
        $messageId = $statusData['message_id'];
        $status = $statusData['status']; // submitted, sent, delivered, read, failed
        $timestamp = $statusData['timestamp'];
        $gatewayId = $statusData['gateway_id'];
        $recipient = $statusData['recipient'];

        if (!$messageId || !$status) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid callback data'
            ]);
        }

        // 1. Update ke WhatsappMessageModel berdasarkan external_id
        $message = WhatsappMessageModel::where('external_id', $messageId)->first();

        if ($message) {
            // Map status NusaSMS ke status database
            $statusMap = [
                'submitted' => 'submitted',
                'sent' => 'sent',
                'delivered' => 'delivered',
                'read' => 'read',
                'failed' => 'failed',
            ];

            $message->update([
                'status' => $statusMap[$status] ?? $status,
                'message_time' => date('Y-m-d H:i:s', $timestamp),
            ]);

            // Update conversation
            if ($message->conversation) {
                $message->conversation->update([
                    'last_message_at' => date('Y-m-d H:i:s', $timestamp),
                    'gateway_id' => $gatewayId,
                ]);
            }

            Log::info('Message status updated', [
                'message_id' => $messageId,
                'status' => $status
            ]);
        }

        // 2. Update ke Kendaraan (jika message_id tersimpan di kendaraan)
        $vehicle = Kendaraan::where('message_id', $messageId)->first();

        if ($vehicle) {
            $statusMapKendaraan = [
                'submitted' => 'sedang_dikirim',
                'sent' => 'sedang_dikirim',
                'delivered' => 'terkirim',
                'read' => 'dibaca',
                'failed' => 'gagal',
            ];

            $updateData = [
                'status_broadcast' => $statusMapKendaraan[$status] ?? $status,
                'tanggal_kirim' => date('Y-m-d H:i:s', $timestamp),
            ];

            if ($status === 'failed') {
                $updateData['keterangan_gagal'] = 'Pengiriman gagal';
            }

            if (in_array($status, ['delivered', 'read'])) {
                $updateData['keterangan_gagal'] = null;
            }

            $vehicle->update($updateData);

            Log::info('Vehicle status updated', [
                'vehicle_id' => $vehicle->id,
                'status' => $status
            ]);
        }

        // Log jika tidak ditemukan
        if (!$message && !$vehicle) {
            Log::warning('Callback: No message or vehicle found', [
                'message_id' => $messageId,
                'recipient' => $recipient
            ]);
        }

        return response()->json(['success' => true]);
    }


    public function statusUpdateCallback_test(Request $request)
    {
        Log::info('WA Status Callback', $request->all());

        $messageId = $request->input('status.message_id');
        $status    = strtolower($request->input('status.status'));
        $timestamp = $request->input('status.timestamp');
        $recipient = $request->input('status.recipient');

        if (!$messageId || !$status) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid callback data'
            ]);
        }

        $vehicle = Kendaraan::where(
            'message_id',
            $messageId
        )->first();

        if (!$vehicle) {
            Log::warning('Kendaraan tidak ditemukan', [
                'message_id' => $messageId
            ]);

            return response()->json([
                'success' => true
            ]);
        }

        $statusMap = [
            'submitted' => 'antrian',
            'sent'      => 'sedang_dikirim',
            'delivered' => 'terkirim',
            'read'      => 'dibaca',
            'failed'    => 'gagal',
            'info'      => 'info',
        ];

        $updateData = [
            'status_broadcast' => $statusMap[$status] ?? 'unknown',
        ];

        if (in_array($status, ['delivered', 'read'])) {
            $updateData['tanggal_kirim'] =
                date('Y-m-d H:i:s', $timestamp);
        }

        $vehicle->update($updateData);

        return response()->json([
            'success' => true
        ]);
    }
}
