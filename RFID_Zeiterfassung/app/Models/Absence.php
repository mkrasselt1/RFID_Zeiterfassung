<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An absence request over a date range with single-step approval.
 */
class Absence extends Model
{
    public const TYPE_VACATION = 'vacation';
    public const TYPE_SPECIAL = 'special';
    public const TYPE_SICK = 'sick';
    public const TYPE_UNPAID = 'unpaid';
    public const TYPE_OVERTIME = 'overtime_reduction';

    public const TYPES = [
        self::TYPE_VACATION => 'Urlaub',
        self::TYPE_SPECIAL => 'Sonderurlaub',
        self::TYPE_SICK => 'Krank',
        self::TYPE_UNPAID => 'Unbezahlt frei',
        self::TYPE_OVERTIME => 'Überstundenabbau',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING => 'Beantragt',
        self::STATUS_APPROVED => 'Genehmigt',
        self::STATUS_REJECTED => 'Abgelehnt',
        self::STATUS_CANCELLED => 'Storniert',
    ];

    protected $fillable = [
        'employee_id', 'type', 'start_date', 'end_date', 'half_day',
        'status', 'reason', 'approver_id', 'decided_at', 'decision_note',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'half_day' => 'boolean',
        'decided_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function coversDate(Carbon $date): bool
    {
        return $date->betweenIncluded($this->start_date, $this->end_date);
    }

    /** Calendar-day span (half-day single-day requests count as 0.5). */
    public function dayCount(): float
    {
        $days = $this->start_date->diffInDays($this->end_date) + 1;

        return ($this->half_day && $days == 1) ? 0.5 : (float) $days;
    }
}
