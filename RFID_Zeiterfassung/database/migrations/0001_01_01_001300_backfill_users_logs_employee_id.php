<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time, best-effort backfill of `users_logs.employee_id` for rows written
 * before stamping was introduced. Must run while the snapshot `username` column
 * still exists (the next migration drops it).
 *
 * Resolution per row, in order:
 *   1) The employee whose name matches the row's `username` snapshot AND who has
 *      an active contract covering `checkindate`. This is the only signal that
 *      survives a chip reassignment (the legacy `users` row keeps just the
 *      CURRENT holder), and it relies on the "no overlapping employment for a
 *      shared card" assumption to stay unambiguous.
 *   2) If the name is unique but has no/!matching contract, that single employee.
 *   3) Otherwise the card's current holder (`users.employee_id`).
 *   4) Else left NULL (unattributable — e.g. free-text "Korrektur", unlinked card).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users_logs') || ! Schema::hasColumn('users_logs', 'employee_id')
            || ! Schema::hasColumn('users_logs', 'username')) {
            return;
        }

        $employeesByName = DB::table('employees')->get(['id', 'name'])
            ->groupBy(fn ($e) => mb_strtolower(trim((string) $e->name)));
        $contracts = DB::table('contracts')->get(['employee_id', 'valid_from', 'valid_to'])
            ->groupBy('employee_id');
        $holderByCard = DB::table('users')->whereNotNull('employee_id')
            ->pluck('employee_id', 'card_uid');

        $activeOn = function (?int $empId, string $date) use ($contracts): bool {
            if (! $empId) {
                return false;
            }
            foreach ($contracts->get($empId, []) as $c) {
                $from = $c->valid_from ? substr((string) $c->valid_from, 0, 10) : null;
                $to = $c->valid_to ? substr((string) $c->valid_to, 0, 10) : null;
                if ($from && $date < $from) {
                    continue;
                }
                if ($to && $date > $to) {
                    continue;
                }

                return true;
            }

            return false;
        };

        DB::table('users_logs')->orderBy('id')->chunkById(1000, function ($logs) use ($employeesByName, $holderByCard, $activeOn) {
            foreach ($logs as $log) {
                $date = substr((string) $log->checkindate, 0, 10);
                $resolved = null;

                // 1)/2) Snapshot name match, preferring a contract active on the date.
                $named = $employeesByName->get(mb_strtolower(trim((string) $log->username)));
                if ($named) {
                    $active = $named->filter(fn ($e) => $activeOn((int) $e->id, $date));
                    if ($active->count() === 1) {
                        $resolved = (int) $active->first()->id;
                    } elseif ($named->count() === 1) {
                        $resolved = (int) $named->first()->id;
                    }
                }

                // 3) Fall back to the card's current holder.
                if ($resolved === null && isset($holderByCard[$log->card_uid])) {
                    $resolved = (int) $holderByCard[$log->card_uid];
                }

                if ($resolved !== null) {
                    DB::table('users_logs')->where('id', $log->id)->update(['employee_id' => $resolved]);
                }
            }
        });
    }

    public function down(): void
    {
        // Best-effort backfill; nothing to reverse (column drop is handled elsewhere).
    }
};
