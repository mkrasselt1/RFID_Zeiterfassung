<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Half working days such as Heiligabend and Silvester.
 *
 * Beide sind keine gesetzlichen Feiertage und kommen daher nicht aus dem
 * Import — sie werden von Hand angelegt und hiermit als halber Tag markiert.
 * Ein halber Feiertag halbiert das Soll und kostet nur einen halben
 * Urlaubstag, statt wie ein ganzer Feiertag beides auf null zu setzen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('holidays', 'half_day')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->boolean('half_day')->default(false)->after('name');
            });
        }
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('half_day');
        });
    }
};
