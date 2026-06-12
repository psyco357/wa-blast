<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMessageModel extends Model
{
    protected $table = 'whatsapp_messages';
    protected $fillable = [
        'whatsapp_data_id',
        'external_id',
        'direction',
        'type',
        'body',
        'media_url',
        'filename',
        'status',
        'message_time',
    ];

    protected $casts = [
        'message_time' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(
            WhatshapDataModel::class,
            'whatsapp_data_id'
        );
    }
}