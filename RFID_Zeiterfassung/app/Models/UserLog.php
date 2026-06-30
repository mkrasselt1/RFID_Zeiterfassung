<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attendance record (legacy `users_logs` table). Open while card_out=0,
 * closed on check-out. Times are stored as the server wrote them.
 *
 * `employee_id` is stamped at check-in and is the authoritative owner of the
 * record: worktime is attributed by it, and name / personnel number are read
 * from the linked employee (the former denormalized `username` / `serialnumber`
 * snapshots were dropped).
 */
class UserLog extends Model
{
    protected $table = 'users_logs';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
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
        'card_out' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
