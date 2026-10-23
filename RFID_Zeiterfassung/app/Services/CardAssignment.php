<?php

namespace App\Services;

use App\Models\Cardholder;
use App\Models\Employee;
use App\Models\UserLog;
use Carbon\Carbon;

/**
 * Eine Karte einem Mitarbeiter zuordnen — im Panel wie über die Admin-API.
 *
 * A physical chip is a single `users` row (card_uid is unique). If it already
 * exists (enrolled, or held by a previous employee), take it over by
 * re-pointing employee_id instead of inserting a duplicate.
 */
class CardAssignment
{
    public function __construct(private readonly WorktimeService $worktime)
    {
    }

    public function assign(Employee $employee, array $data): Cardholder
    {
        $data['card_uid'] = static::normalizeUid($data['card_uid'] ?? '');

        $card = Cardholder::updateOrCreate(
            ['card_uid' => $data['card_uid']],
            $data + ['employee_id' => $employee->getKey()],
        );

        // Claim this card's not-yet-attributed stampings — e.g. legacy
        // logs from before this employee record existed — and rebuild the
        // affected ledger days. Only NULL-owner logs are touched, so a
        // previous holder's already-attributed history is never moved.
        $orphan = UserLog::where('card_uid', $card->card_uid)->whereNull('employee_id');
        $dates = (clone $orphan)->distinct()->pluck('checkindate');
        if ($dates->isNotEmpty()) {
            $orphan->update(['employee_id' => $employee->getKey()]);
            foreach ($dates as $d) {
                $this->worktime->recalculateDay($employee, Carbon::parse(substr((string) $d, 0, 10)));
            }
        }

        return $card;
    }

    /** Firmware format: uppercase hex, no separators (see src/main.cpp). */
    public static function normalizeUid(string $uid): string
    {
        return strtoupper(preg_replace('/[^0-9a-fA-F]/', '', $uid));
    }
}
