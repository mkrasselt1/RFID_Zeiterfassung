<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual correction to an employee's overtime balance.
 *
 * Gebucht wird neben dem Arbeitszeitkonto, nicht hinein: der Saldo ist die
 * Summe aus den Ledger-Tagen und diesen Korrekturen. Damit bleiben die
 * Nachweise der Vergangenheit unverändert, während der Zähler stimmt.
 */
class BalanceAdjustment extends Model
{
    protected $fillable = ['employee_id', 'effective_date', 'minutes', 'note', 'created_by'];

    protected $casts = [
        'effective_date' => 'date',
        'minutes' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
