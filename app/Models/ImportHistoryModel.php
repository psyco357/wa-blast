<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportHistoryModel extends Model
{
    protected $table = 'import_histories';

    protected $fillable = [
        'file_name',
        'stored_path',
        'mode',
        'queue_name',
        'status',
        'success_count',
        'fail_count',
        'errors',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
