<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily balance tolerance.
 *
 * `contracts.balance_tolerance_minutes` overrides the global default from
 * `settings`; null means "use the global one". It is a threshold, not a
 * deduction: a day whose balance stays below it counts as zero, at or above it
 * the full balance counts.
 *
 * `work_days.raw_balance_minutes` keeps the untouched deviation so the calendar
 * can still show what actually happened, while `balance_minutes` — the column
 * every sum and carryover builds on — holds the tolerated value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contracts', 'balance_tolerance_minutes')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->unsignedSmallInteger('balance_tolerance_minutes')->nullable()->after('break_rules');
            });
        }

        if (! Schema::hasColumn('work_days', 'raw_balance_minutes')) {
            Schema::table('work_days', function (Blueprint $table) {
                $table->integer('raw_balance_minutes')->default(0)->after('balance_minutes');
            });

            // Existing rows carry no tolerance yet: raw equals the stored balance
            // until `worktime:recalc` rebuilds them.
            DB::table('work_days')->update(['raw_balance_minutes' => DB::raw('balance_minutes')]);
        }
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('balance_tolerance_minutes');
        });
        Schema::table('work_days', function (Blueprint $table) {
            $table->dropColumn('raw_balance_minutes');
        });
    }
};
