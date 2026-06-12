<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WhatshapDataModel extends Model
{
    protected $table = 'whatsapp_data';
    protected $fillable = [
        'kendaraan_id',
        'nomor_wa',
        'gateway_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function kendaraan(): BelongsTo
    {
        return $this->belongsTo(KendaraanModel::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessageModel::class, 'whatsapp_data_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(WhatsappMessageModel::class, 'whatsapp_data_id')
            ->latestOfMany('message_time');
    }
}