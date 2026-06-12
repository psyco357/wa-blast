<?php
// app/Http/Controllers/Api/OmniChannelController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappConversationModel;
use App\Models\KendaraanModel;
use Illuminate\Http\Request;
use App\Models\WhatshapDataModel;
use App\Models\WhatsappMessageModel;
use Illuminate\Support\Facades\Log;
use App\Helpers\TemplateWaBlast;



class OmniChannelController extends Controller
{

    // ambil data semua inbox
    public function getDataInbox()
    {
        // Ambil dari WhatshapDataModel, bukan WhatsappConversationModel
        $whatsappData = WhatshapDataModel::with([
            'kendaraan',
            'latestMessage' => function ($query) {
                $query->select([
                    'whatsapp_messages.id',
                    'whatsapp_messages.whatsapp_data_id',
                    'whatsapp_messages.direction',
                    'whatsapp_messages.type',
                    'whatsapp_messages.body',
                    'whatsapp_messages.media_url',
                    'whatsapp_messages.filename',
                    'whatsapp_messages.status',
                    'whatsapp_messages.message_time',
                ]);
            },
        ])
            ->select('id', 'kendaraan_id', 'nomor_wa', 'gateway_id', 'last_message_at')
            ->orderBy('last_message_at', 'desc')
            ->get();
        return response()->json([
            'success' => true,
            'data' => $whatsappData
        ]);
    }

    // ambil data percakapan berdasarkan id whatsapp_data
    public function getDataPercakapan(int $whatsappDataId)
    {
        // Ambil data WA dan semua pesannya
        $whatsappData = WhatshapDataModel::with(['kendaraan', 'messages' => function ($query) {
            $query->orderBy('message_time', 'asc'); // Urutkan dari lama ke baru
        }])->find($whatsappDataId);

        if (!$whatsappData) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp data not found'
            ], 404);
        }


        return response()->json([
            'success' => true,
            'data' => [
                'id' => $whatsappData->id,
                'kendaraan' => $whatsappData->kendaraan,
                'nomor_wa' => $whatsappData->nomor_wa,
                'gateway_id' => $whatsappData->gateway_id,
                'messages' => $whatsappData->messages, // Semua pesan
                'last_message_at' => $whatsappData->last_message_at
            ]
        ]);
    }


    public function sendReply(Request $request)
    {
        $request->validate([
            'whatsapp_data_id' => 'required|exists:whatsapp_data,id',
            'type' => 'required|in:text,image,document,video',
            'message' => 'nullable|string',
            'link' => 'nullable|string',
            'filename' => 'nullable|string',
        ]);

        $conversation = WhatshapDataModel::findOrFail(
            $request->whatsapp_data_id
        );

        $payloads = [];

        switch ($request->type) {

            case 'text':
                $payloads = [
                    'body' => $request->message,
                ];
                break;

            case 'image':
                $payloads = [
                    'link' => $request->link,
                    'caption' => $request->message,
                ];
                break;

            case 'document':
                $payloads = [
                    'link' => $request->link,
                    'caption' => $request->message,
                    'filename' => $request->filename,
                ];
                break;

            case 'video':
                $payloads = [
                    'link' => $request->link,
                    'caption' => $request->message,
                ];
                break;
        }

        $response = TemplateWaBlast::replyMessage(
            $conversation->nomor_wa,
            $request->type,
            $payloads
        );
        Log::info('Reply Message Response', $response);
        $message = WhatsappMessageModel::create([
            'whatsapp_data_id' => $conversation->id,
            'external_id' => $response['data']['message_id'] ?? null,
            'direction' => 'outgoing',
            'type' => $request->type,
            'body' => $request->message,
            'media_url' => $request->link,
            'filename' => $request->filename,
            'status' => 'submitted',
            'message_time' => now(),
        ]);

        $conversation->update([
            'last_message_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $message,
        ]);
    }


    public function webhookIncoming_test(Request $request)
    {
        Log::info('NusaSMS Inbox', $request->all());

        $payload = $request->input('inbox');

        $messageId    = $payload['message_id'] ?? null;
        $gatewayNo    = $payload['gateway']['number'] ?? null;
        $senderNumber = $payload['sender']['number'] ?? null;
        $message      = $payload['message']['text']['body'] ?? null;
        $timestamp    = $payload['timestamp'] ?? null;

        $vehicle = KendaraanModel::where(
            'no_telepon',
            $this->formatPhoneNumber($senderNumber)
        )->first();

        // dd($vehicle);
        $exists = WhatsappConversationModel::where(
            'external_id',
            $messageId
        )->exists();

        if ($exists) {
            return response()->json([
                'success' => true
            ]);
        }

        WhatsappConversationModel::create([
            'kendaraan_id'   => $vehicle?->id,
            'external_id'    => $messageId,
            'gateway_number' => $gatewayNo,
            'nomor_wa'       => $senderNumber,
            'pesan_masuk'    => $message,
            'jenis'          => 'incoming',
            'waktu_pesan'    => date('Y-m-d H:i:s', $timestamp),
            'dibaca'         => false,
        ]);

        return response()->json([
            'success' => true
        ]);
    }

    public function webhookIncoming_inbox(Request $request)
    {
        // dd($request->all());

        Log::info('NusaSMS Inbox', $request->all());

        $payload = $request->input('inbox');

        $messageId    = $payload['message_id'] ?? null;
        $gatewayNo    = $payload['gateway']['number'] ?? null;
        $senderNumber = $payload['sender']['number'] ?? null;
        $message      = $payload['message']['text']['body'] ?? null;
        $timestamp    = $payload['timestamp'] ?? time();

        $vehicle = KendaraanModel::where(
            'no_telepon',
            $this->formatPhoneNumber($senderNumber)
        )->first();

        $conversation = WhatshapDataModel::firstOrCreate(
            [
                'nomor_wa' => $senderNumber,
            ],
            [
                'kendaraan_id' => $vehicle?->id,
                'gateway_id'   => $gatewayNo,
            ]
        );

        $exists = WhatsappMessageModel::where(
            'external_id',
            $messageId
        )->exists();

        if ($exists) {
            return response()->json([
                'success' => true
            ]);
        }

        $conversation->messages()->create([
            'external_id' => $messageId,
            'direction'   => 'incoming',
            'type'        => 'text',
            'body'        => $message,
            'status'      => null,
            'message_time' => date('Y-m-d H:i:s', $timestamp),
        ]);

        $conversation->update([
            'last_message_at' => date('Y-m-d H:i:s', $timestamp),
        ]);

        return response()->json([
            'success' => true
        ]);
    }

    public function webhookIncoming(Request $request)
    {
        Log::info('NusaSMS Webhook', $request->all());

        $type = $request->input('type');
        $payload = $request->all();

        // AKSI 1: Handle Status Pengiriman
        if ($type === 'status') {
            return $this->handleStatus($payload);
        }

        // AKSI 2: Handle Inbox (kode Anda yang sudah ada)
        if ($type === 'inbox') {
            return $this->handleInbox($payload);
        }

        // Jika tipe tidak dikenal
        Log::warning('Unknown webhook type', ['type' => $type]);
        return response()->json(['success' => false, 'message' => 'Unknown type'], 400);
    }

    private function handleInbox(array $payload)
    {
        // PERBAIKAN: Ambil data dari key 'inbox'
        $inboxData = $payload['inbox'] ?? $payload; // Fallback untuk backward compatibility

        Log::info('NusaSMS Inbox', $inboxData);

        $messageId    = $inboxData['message_id'] ?? null;
        $gatewayNo    = $inboxData['gateway']['number'] ?? null;
        $senderNumber = $inboxData['sender']['number'] ?? null;
        $message      = $inboxData['message']['text']['body'] ?? null;
        $timestamp    = $inboxData['timestamp'] ?? time();

        // Rest of your code remains the SAME...
        $vehicle = KendaraanModel::where(
            'no_telepon',
            $this->formatPhoneNumber($senderNumber)
        )->first();

        $conversation = WhatshapDataModel::firstOrCreate(
            [
                'nomor_wa' => $senderNumber,
            ],
            [
                'kendaraan_id' => $vehicle?->id,
                'gateway_id'   => $gatewayNo,
            ]
        );

        $exists = WhatsappMessageModel::where(
            'external_id',
            $messageId
        )->exists();

        if ($exists) {
            return response()->json([
                'success' => true
            ]);
        }

        $conversation->messages()->create([
            'external_id' => $messageId,
            'direction'   => 'incoming',
            'type'        => 'text',
            'body'        => $message,
            'status'      => null,
            'message_time' => date('Y-m-d H:i:s', $timestamp),
        ]);

        $conversation->update([
            'last_message_at' => date('Y-m-d H:i:s', $timestamp),
        ]);

        return response()->json([
            'success' => true
        ]);
    }

    private function handleStatus(array $payload)
    {
        // PERBAIKAN: Ambil data dari key 'status'
        $statusData = $payload['status'] ?? $payload; // Fallback untuk backward compatibility

        Log::info('NusaSMS Status Callback', $statusData);

        // Hapus pengecekan ini karena $statusData sekarang sudah berisi data status
        // if ($statusData['type'] !== 'status') {
        //     return response()->json(['success' => true]);
        // }

        $messageId = $statusData['message_id'] ?? null;
        $status = $statusData['status'] ?? null; // submitted, sent, delivered, read, failed
        $timestamp = $statusData['timestamp'] ?? time();
        $gatewayId = $statusData['gateway_id'] ?? null;
        $recipient = $statusData['recipient'] ?? null;

        if (!$messageId || !$status) {
            Log::error('Invalid status callback data', ['statusData' => $statusData]);
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
        $vehicle = KendaraanModel::where('message_id', $messageId)->first();

        if ($vehicle) {
            $statusMapKendaraan = [
                'submitted' => 'antrian',
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

    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '62')) {
            $phone = substr($phone, 2);
        }

        return $phone;
    }
}
