<?php
// app/Models/KendaraanModel.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KendaraanModel extends Model
{
    protected $table = 'kendaraan';
    protected $fillable = [
        'kode_wilayah',
        'jenis_roda',
        'nomor_polisi',
        'nama_pemilik',
        'tanggal_akhir_pajak',
        'no_telepon',
        'jumlah_tagihan',
        'status_broadcast',
        'pesan_blast',
        'tanggal_kirim',
        'keterangan_gagal'
    ];

    protected $casts = [
        'tanggal_akhir_pajak' => 'date',
        'tanggal_kirim' => 'datetime',
        'jumlah_tagihan' => 'decimal:2'
    ];

    public function broadcastLogs()
    {
        return $this->hasMany(BroadcastLogModel::class, 'kendaraan_id');
    }

    public function conversations()
    {
        return $this->hasMany(WhatsappConversationModel::class, 'kendaraan_id');
    }
}
