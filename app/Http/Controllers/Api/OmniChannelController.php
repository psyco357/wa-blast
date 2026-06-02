<?php
// app/Http/Controllers/Api/OmniChannelController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappConversationModel;
use App\Models\KendaraanModel;
use Illuminate\Http\Request;

class OmniChannelController extends Controller
{
    public function getConversations(Request $request, $kendaraanId = null)
    {
        if ($kendaraanId) {
            $conversations = WhatsappConversationModel::where('kendaraan_id', $kendaraanId)
                ->orderBy('waktu_pesan', 'asc')
                ->get();

            $kendaraan = KendaraanModel::findOrFail($kendaraanId);

            return response()->json([
                'success' => true,
                'kendaraan' => $kendaraan,
                'conversations' => $conversations
            ]);
        }

        // Get all conversations grouped by vehicle
        $conversations = WhatsappConversationModel::with('kendaraan')
            ->orderBy('waktu_pesan', 'desc')
            ->get()
            ->groupBy('kendaraan_id');

        $result = [];
        foreach ($conversations as $kendaraanId => $items) {
            $kendaraan = KendaraanModel::find($kendaraanId);
            if ($kendaraan) {
                $result[] = [
                    'kendaraan' => $kendaraan,
                    'last_message' => $items->first(),
                    'unread_count' => $items->where('dibaca', false)->where('jenis', 'incoming')->count(),
                    'total_messages' => $items->count()
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

    public function sendReply(Request $request)
    {
        $request->validate([
            'kendaraan_id' => 'required|exists:kendaraan_models,id',
            'message' => 'required|string',
            'nomor_wa' => 'required|string'
        ]);

        // Save outgoing message
        $conversation = WhatsappConversationModel::create([
            'kendaraan_id' => $request->kendaraan_id,
            'nomor_wa' => $request->nomor_wa,
            'pesan_keluar' => $request->message,
            'jenis' => 'outgoing',
            'waktu_pesan' => now(),
            'dibaca' => true
        ]);

        // Here you would integrate with your WhatsApp API to send the actual message
        // $this->sendWhatsAppMessage($request->nomor_wa, $request->message);

        return response()->json([
            'success' => true,
            'message' => 'Balasan terkirim',
            'data' => $conversation
        ]);
    }

    public function markAsRead($conversationId)
    {
        $conversation = WhatsappConversationModel::findOrFail($conversationId);
        $conversation->update(['dibaca' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Pesan ditandai sudah dibaca'
        ]);
    }

    public function webhookIncoming(Request $request)
    {
        // Webhook untuk menerima pesan masuk dari WhatsApp
        $data = $request->all();

        // Find vehicle by phone number
        $vehicle = KendaraanModel::where('no_telepon', $data['from'])->first();

        if ($vehicle) {
            WhatsappConversationModel::create([
                'kendaraan_id' => $vehicle->id,
                'nomor_wa' => $data['from'],
                'pesan_masuk' => $data['message'],
                'jenis' => 'incoming',
                'waktu_pesan' => now(),
                'dibaca' => false
            ]);
        }

        return response()->json(['success' => true]);
    }
}
