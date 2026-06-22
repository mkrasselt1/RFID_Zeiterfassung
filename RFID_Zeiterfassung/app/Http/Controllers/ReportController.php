<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\WorktimeReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the per-employee monthly worktime report as a downloadable PDF.
 * Managers may view anyone; employees only themselves.
 */
class ReportController extends Controller
{
    public function worktimePdf(Employee $employee, int $year, int $month): Response
    {
        $user = Auth::user();
        abort_unless($user->canManagePeople() || $user->id === $employee->id, 403);

        $report = app(WorktimeReport::class)->forMonth($employee, $year, $month);

        $pdf = Pdf::loadView('reports.worktime-pdf', ['r' => $report])
            ->setPaper('a4', 'portrait');

        $filename = sprintf(
            'arbeitszeitnachweis_%s_%s.pdf',
            str($employee->name)->slug(),
            $report['period']->format('Y-m'),
        );

        return $pdf->download($filename);
    }
}
