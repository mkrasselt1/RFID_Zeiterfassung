<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An RFID reader device (legacy `devices` table).
 */
class Device extends Model
{
    public const MODE_LEARN = 0; // register new cards
    public const MODE_TIME = 1;  // attendance check-in/out

    protected $table = 'devices';

    public $timestamps = false;

    protected $fillable = [
        'device_name',
        'device_dep',
        'device_uid',
        'device_date',
        'device_mode',
    ];

    protected $casts = [
        'device_date' => 'date',
        'device_mode' => 'integer',
    ];
}
