<?php

namespace App\Models;

use Carbon\Carbon;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The central identity: logs into the panel and owns cards, contracts, the
 * work-day ledger and absence requests. Replaces Admin as the auth model.
 */
class Employee extends Authenticatable implements FilamentUser, HasName
{
    use Notifiable;

    public const ROLE_EMPLOYEE = 'employee';
    public const ROLE_SUPERVISOR = 'supervisor';
    public const ROLE_HR = 'hr';
    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'name', 'email', 'password', 'personnel_number', 'role',
        'supervisor_id', 'is_active', 'gender', 'calendar_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    // --- Relations -------------------------------------------------------

    public function cards(): HasMany
    {
        return $this->hasMany(Cardholder::class, 'employee_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    public function workDays(): HasMany
    {
        return $this->hasMany(WorkDay::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
    }

    // --- Roles -----------------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isHr(): bool
    {
        return $this->role === self::ROLE_HR;
    }

    public function isSupervisor(): bool
    {
        return $this->role === self::ROLE_SUPERVISOR;
    }

    /** Admin or HR — the roles that manage people and approve absences. */
    public function canManagePeople(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_HR], true);
    }

    // --- Domain helpers --------------------------------------------------

    public function activeContractOn(Carbon|string $date): ?Contract
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return $this->contracts()
            ->whereDate('valid_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
            })
            ->orderByDesc('valid_from')
            ->first();
    }

    /** Remaining vacation days for a year: entitlement minus approved vacation. */
    public function vacationBalance(int $year): float
    {
        $contract = $this->activeContractOn(Carbon::create($year, 6, 1));
        $entitlement = (float) ($contract?->vacation_days_per_year ?? 0);

        $taken = $this->absences()
            ->approved()
            ->where('type', Absence::TYPE_VACATION)
            ->whereYear('start_date', $year)
            ->get()
            ->sum(fn (Absence $a) => $a->dayCount());

        return $entitlement - $taken;
    }

    /** Net overtime in minutes across the ledger (from the go-live cut-off, if set). */
    public function overtimeBalanceMinutes(): int
    {
        $query = $this->workDays();
        if ($start = Setting::get('tracking_start')) {
            $query->where('work_date', '>=', $start);
        }

        return (int) $query->sum('balance_minutes');
    }

    // --- Filament --------------------------------------------------------

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }
}
