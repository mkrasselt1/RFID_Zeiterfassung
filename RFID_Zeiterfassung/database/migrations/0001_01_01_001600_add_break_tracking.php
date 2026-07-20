<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Break (Pause) handling.
 *
 * `contracts.break_rules` is an optional JSON staircase overriding the global
 * default from `settings`: [{"from_minutes":360,"minutes":30}, ...] — null means
 * "use the global default".
 *
 * `work_days` keeps the deduction auditable: `gross_minutes` is the stamped
 * attendance, `break_minutes` the automatic deduction, and `worked_minutes`
 * stays the net time every report and balance already builds on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contracts', 'break_rules')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->json('break_rules')->nullable()->after('workdays');
            });
        }

        Schema::table('work_days', function (Blueprint $table) {
            if (! Schema::hasColumn('work_days', 'gross_minutes')) {
                $table->integer('gross_minutes')->default(0)->after('work_date');
            }
            if (! Schema::hasColumn('work_days', 'break_minutes')) {
                $table->integer('break_minutes')->default(0)->after('gross_minutes');
            }
        });

        // Existing rows carry no deduction yet — gross equals the stored net
        // until `worktime:recalc` rebuilds them.
        DB::table('work_days')->where('gross_minutes', 0)
            ->update(['gross_minutes' => DB::raw('worked_minutes')]);
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('break_rules');
        });
        Schema::table('work_days', function (Blueprint $table) {
            $table->dropColumn(['gross_minutes', 'break_minutes']);
        });
    }
};
