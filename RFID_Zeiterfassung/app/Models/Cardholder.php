<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An RFID card (legacy `users` table). `add_card` marks a fully registered
 * card; `card_select` is the transient "selected in learn mode" flag.
 * `device_dep` may be a department name or 'All'. A card optionally belongs to
 * an employee (an employee may hold several cards).
 */
class Cardholder extends Model
{
    protected $table = 'users';

    public $timestamps = false;

    protected $fillable = [
        'username',
        'serialnumber',
        'gender',
        'email',
        'card_uid',
        'card_select',
        'calendarId',
        'user_date',
        'device_uid',
        'device_dep',
        'add_card',
        'employee_id',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected $casts = [
        'serialnumber' => 'float',
        'card_select' => 'boolean',
        'add_card' => 'boolean',
        'user_date' => 'date',
    ];
}
