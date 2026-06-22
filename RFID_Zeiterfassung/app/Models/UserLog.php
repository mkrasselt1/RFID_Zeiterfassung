<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An attendance record (legacy `users_logs` table). Open while card_out=0,
 * closed on check-out. Times are stored as the server wrote them.
 */
class UserLog extends Model
{
    protected $table = 'users_logs';

    public $timestamps = false;

    protected $fillable = [
        'username',
        'serialnumber',
        'card_uid',
        'device_uid',
        'device_dep',
        'checkindate',
        'timein',
        'timeout',
        'calendarEventId',
        'card_out',
    ];

    // checkindate is intentionally left uncast: it is stored and compared as a
    // plain 'Y-m-d' string (legacy MySQL DATE), so open-log lookups match.
    protected $casts = [
        'serialnumber' => 'float',
        'card_out' => 'boolean',
    ];
}
