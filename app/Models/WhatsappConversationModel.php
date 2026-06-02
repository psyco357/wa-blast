<?php
// app/Models/WhatsappConversationModel.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappConversationModel extends Model
{
    protected $table = 'whatsapp_conversations';
    protected $fillable = [
        'kendaraan_id',
        'nomor_wa',
        'pesan_masuk',
        'pesan_keluar',
        'jenis',
        'waktu_pesan',
        'dibaca'
    ];

    protected $casts = [
        'waktu_pesan' => 'datetime',
        'dibaca' => 'boolean'
    ];

    public function kendaraan()
    {
        return $this->belongsTo(KendaraanModel::class, 'kendaraan_id');
    }
}
