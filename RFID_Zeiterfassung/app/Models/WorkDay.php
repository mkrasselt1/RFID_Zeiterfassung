<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivered-worktime ledger row per employee per day, recomputed by
 * WorktimeService from attendance logs and approved absences.
 */
class WorkDay extends Model
{
    protected $fillable = [
        'employee_id', 'work_date', 'worked_minutes',
        'expected_minutes', 'balance_minutes', 'absence_id',
    ];

    // work_date is left uncast: stored/compared as a plain 'Y-m-d' string so
    // updateOrCreate lookups match (a 'date' cast serializes to 'Y-m-d H:i:s'
    // and would never match the bare date, causing duplicate inserts).
    protected $casts = [
        'worked_minutes' => 'integer',
        'expected_minutes' => 'integer',
        'balance_minutes' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function absence(): BelongsTo
    {
        return $this->belongsTo(Absence::class);
    }
}
