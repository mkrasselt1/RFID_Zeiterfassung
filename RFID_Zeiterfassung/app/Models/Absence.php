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

    /** "30" / "4,5" / "0" — halbe Tage bleiben, glatte verlieren die Null. */
    public static function formatDays(float $days): string
    {
        return rtrim(rtrim(number_format($days, 1, ',', '.'), '0'), ',');
    }

    public function coversDate(Carbon $date): bool
    {
        return $date->betweenIncluded($this->start_date, $this->end_date);
    }

    /**
     * Days this absence actually consumes: workdays per the employee's contract,
     * public holidays excluded. Ein Urlaub von Montag bis Sonntag kostet fünf
     * Tage, nicht sieben — Wochenenden und Feiertage sind ohnehin frei.
     *
     * Der Vertrag wird einmal zum Startdatum bestimmt; ein Vertragswechsel
     * mitten im Urlaub ist selten genug, um dafür nicht pro Tag zu fragen.
     */
    public function dayCount(?Carbon $from = null, ?Carbon $to = null): float
    {
        // Ein Antrag, der über das Fenster hinausragt, zählt nur mit dem Teil
        // darin — sonst stünde im April-Nachweis schon der ganze Mai-Urlaub.
        $start = ($from && $from->gt($this->start_date)) ? $from->copy() : $this->start_date->copy();
        $end = ($to && $to->lt($this->end_date)) ? $to->copy() : $this->end_date->copy();
        if ($start->gt($end)) {
            return 0.0;
        }

        $workdays = $this->employee?->activeContractOn($this->start_date)?->workdayList()
            ?? [1, 2, 3, 4, 5];

        $days = 0.0;
        for ($day = $start; $day->lte($end); $day->addDay()) {
            if (in_array((int) $day->isoWeekday(), $workdays, true) && ! Holiday::isHoliday($day)) {
                $days++;
            }
        }

        // Ein halber Tag ist nur bei einem eintägigen Antrag gemeint; fällt der
        // auf einen freien Tag, bleibt es bei 0.
        return ($this->half_day && $this->start_date->equalTo($this->end_date))
            ? $days * 0.5
            : $days;
    }
}
