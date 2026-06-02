<?php
// app/Models/BroadcastLogModel.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BroadcastLogModel extends Model
{
    protected $table = 'broadcast_logs';
    protected $fillable = [
        'kendaraan_id',
        'no_tujuan',
        'pesan',
        'status',
        'response',
        'sent_at'
    ];

    protected $casts = [
        'sent_at' => 'datetime'
    ];

    public function kendaraan()
    {
        return $this->belongsTo(KendaraanModel::class, 'kendaraan_id');
    }
}
