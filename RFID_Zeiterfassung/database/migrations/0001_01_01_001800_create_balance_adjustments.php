<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual corrections to an employee's overtime balance.
 *
 * Das Arbeitszeitkonto ist ein Rechenergebnis aus Stempelungen und Vertrag —
 * es lässt sich nicht bearbeiten, und das soll auch so bleiben. Altbestände,
 * Übernahmen aus einem Vorsystem oder eine vereinbarte Kappung zum
 * Jahreswechsel werden deshalb daneben gebucht statt hineingeschrieben: der
 * Saldo ist die Summe aus Ledger und diesen Korrekturen, die Historie bleibt
 * unangetastet und jede Korrektur trägt ihren Grund.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Wirkt zu diesem Tag: entscheidet, in welches Jahr die Korrektur
            // fällt und ab wann ein Nachweis sie zeigt.
            $table->date('effective_date');
            // Vorzeichenbehaftet: minus baut ab, plus schreibt gut.
            $table->integer('minutes');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_adjustments');
    }
};
