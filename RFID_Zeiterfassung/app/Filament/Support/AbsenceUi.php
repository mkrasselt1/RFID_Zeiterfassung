<?php

namespace App\Filament\Support;

use App\Models\Absence;
use App\Services\WorktimeService;
use Filament\Forms;
use Filament\Tables;

/**
 * Shared absence form/table/action definitions, reused by AbsenceResource and
 * the EmployeeResource absences relation manager so the two stay in sync.
 */
class AbsenceUi
{
    /** @return array<Forms\Components\Component> */
    public static function formSchema(): array
    {
        return [
            Forms\Components\Select::make('type')->label('Art')
                ->options(Absence::TYPES)->required()->live(),
            Forms\Components\DatePicker::make('start_date')->label('Von')->required()->default(now()),
            Forms\Components\DatePicker::make('end_date')->label('Bis')->required()->default(now())
                ->afterOrEqual('start_date'),
            Forms\Components\Toggle::make('half_day')->label('Halber Tag (nur bei eintägig)'),
            Forms\Components\Textarea::make('reason')->label('Begründung')->columnSpanFull()
                // Bei Sonderurlaub ist der Anlass nachzuweisen – nur im Formular erzwungen,
                // nicht als DB-Constraint (Seeder/Importe/Altdaten bleiben gültig).
                ->required(fn (Forms\Get $get) => $get('type') === Absence::TYPE_SPECIAL)
                ->helperText(fn (Forms\Get $get) => $get('type') === Absence::TYPE_SPECIAL
                    ? 'Bei Sonderurlaub bitte den Anlass angeben (z. B. Todesfall, Hochzeit).' : null),
        ];
    }

    /** @return array<Tables\Columns\Column> */
    public static function columns(): array
    {
        return [
            Tables\Columns\TextColumn::make('type')->label('Art')->badge()
                ->formatStateUsing(fn (string $state) => Absence::TYPES[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    Absence::TYPE_VACATION => 'success',
                    Absence::TYPE_SPECIAL => 'warning',
                    Absence::TYPE_SICK => 'danger',
                    Absence::TYPE_OVERTIME => 'info',
                    default => 'gray',
                }),
            Tables\Columns\TextColumn::make('start_date')->label('Von')->date('d.m.Y')->sortable(),
            Tables\Columns\TextColumn::make('end_date')->label('Bis')->date('d.m.Y'),
            Tables\Columns\TextColumn::make('days')->label('Tage')
                ->state(fn (Absence $r) => $r->dayCount()),
            Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (string $state) => Absence::STATUSES[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    Absence::STATUS_APPROVED => 'success',
                    Absence::STATUS_REJECTED => 'danger',
                    Absence::STATUS_CANCELLED => 'gray',
                    default => 'warning',
                }),
            Tables\Columns\TextColumn::make('approver.name')->label('Entschieden von')->placeholder('-')->toggleable(),
        ];
    }

    /**
     * Approve / reject actions for HR/Admin. Available on ANY status (except the
     * one already set), so a decision can be revised at any time; the ledger is
     * recomputed after each change.
     *
     * @return array<Tables\Actions\Action>
     */
    public static function decisionActions(): array
    {
        $isManager = fn () => auth()->user()?->canManagePeople() ?? false;

        return [
            Tables\Actions\Action::make('approve')->label('Genehmigen')
                ->icon('heroicon-o-check')->color('success')
                ->visible(fn (Absence $r) => $isManager() && $r->status !== Absence::STATUS_APPROVED)
                ->requiresConfirmation()
                ->action(function (Absence $record) {
                    $record->update([
                        'status' => Absence::STATUS_APPROVED,
                        'approver_id' => auth()->id(),
                        'decided_at' => now(),
                    ]);
                    app(WorktimeService::class)->recalculateForAbsence($record);
                }),
            Tables\Actions\Action::make('reject')->label('Ablehnen')
                ->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (Absence $r) => $isManager() && $r->status !== Absence::STATUS_REJECTED)
                ->requiresConfirmation()
                ->form([
                    Forms\Components\Textarea::make('decision_note')->label('Begründung'),
                ])
                ->action(function (Absence $record, array $data) {
                    $record->update([
                        'status' => Absence::STATUS_REJECTED,
                        'approver_id' => auth()->id(),
                        'decided_at' => now(),
                        'decision_note' => $data['decision_note'] ?? null,
                    ]);
                    app(WorktimeService::class)->recalculateForAbsence($record);
                }),
        ];
    }
}
