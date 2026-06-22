<?php

namespace App\Filament\Pages;

use App\Models\Employee;
use App\Services\WorktimeReport;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Per-employee monthly worktime report (Arbeitszeitnachweis). Managers pick any
 * employee; employees see only their own. Whole weeks render as 7-day blocks.
 * A PDF of the same data is available via the header action.
 */
class WorktimeReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Zeiterfassung';

    protected static ?string $navigationLabel = 'Arbeitszeitnachweis';

    protected static ?string $title = 'Arbeitszeitnachweis';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.worktime-report';

    public ?array $data = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('PDF herunterladen')
                ->icon('heroicon-o-arrow-down-tray')
                ->action('downloadPdf'),
        ];
    }

    public function mount(): void
    {
        $this->form->fill([
            'employee_id' => auth()->id(),
            'period' => now()->format('Y-m'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('employee_id')
                ->label('Mitarbeiter')
                ->options(fn () => Employee::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->live()
                ->disabled(fn () => ! (auth()->user()?->canManagePeople() ?? false)),
            Select::make('period')
                ->label('Monat')
                ->options($this->monthOptions())
                ->live(),
        ])->columns(2)->statePath('data');
    }

    /** Last 18 months as 'Y-m' => 'Monat Jahr'. */
    protected function monthOptions(): array
    {
        $options = [];
        $cursor = now()->startOfMonth();
        for ($i = 0; $i < 18; $i++) {
            $options[$cursor->format('Y-m')] = $cursor->translatedFormat('F Y');
            $cursor->subMonth();
        }

        return $options;
    }

    /** Resolve the employee the current user is allowed to view. */
    protected function resolveEmployee(): Employee
    {
        $user = auth()->user();
        if (! $user->canManagePeople()) {
            return $user;
        }

        return Employee::find($this->data['employee_id'] ?? null) ?? $user;
    }

    public function getReport(): array
    {
        $employee = $this->resolveEmployee();
        [$year, $month] = explode('-', $this->data['period'] ?? now()->format('Y-m'));

        return app(WorktimeReport::class)->forMonth($employee, (int) $year, (int) $month);
    }

    public function downloadPdf()
    {
        $employee = $this->resolveEmployee();
        [$year, $month] = explode('-', $this->data['period'] ?? now()->format('Y-m'));

        return redirect()->route('reports.worktime', [
            'employee' => $employee->id,
            'year' => (int) $year,
            'month' => (int) $month,
        ]);
    }
}
