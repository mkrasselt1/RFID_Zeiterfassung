<?php

namespace App\Observers;

use App\Models\UserLog;
use App\Models\UserLogChange;
use Illuminate\Support\Facades\Auth;

/**
 * Schreibt jeden Eingriff einer angemeldeten Person in das Änderungsprotokoll,
 * egal ob im Panel oder über die Admin-API. Leser und Kiosk melden niemanden
 * an — ihr normales Stempeln bleibt draußen, sonst ginge das Protokoll darin
 * unter.
 */
class UserLogObserver
{
    /** Felder, deren Änderung zählt; der Rest ist Gerätetechnik. */
    private const TRACKED = ['employee_id', 'card_uid', 'checkindate', 'timein', 'timeout', 'card_out', 'source'];

    public function created(UserLog $log): void
    {
        $this->record($log, UserLogChange::ACTION_CREATED, null, $this->snapshot($log->getAttributes()));
    }

    public function updated(UserLog $log): void
    {
        $changed = array_intersect(array_keys($log->getChanges()), self::TRACKED);
        if ($changed === []) {
            return;
        }

        $before = array_intersect_key($log->getOriginal(), array_flip($changed));
        $after = array_intersect_key($log->getAttributes(), array_flip($changed));
        $this->record($log, UserLogChange::ACTION_UPDATED, $before, $after);
    }

    public function deleted(UserLog $log): void
    {
        $this->record($log, UserLogChange::ACTION_DELETED, $this->snapshot($log->getAttributes()), null);
    }

    private function snapshot(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(self::TRACKED));
    }

    private function record(UserLog $log, string $action, ?array $before, ?array $after): void
    {
        if (! Auth::check()) {
            return;
        }

        UserLogChange::create([
            'user_log_id' => $log->id,
            'employee_id' => $log->employee_id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'changed_by' => Auth::id(),
            'channel' => request()->is('api/*') ? UserLogChange::CHANNEL_API : UserLogChange::CHANNEL_PANEL,
        ]);
    }
}
