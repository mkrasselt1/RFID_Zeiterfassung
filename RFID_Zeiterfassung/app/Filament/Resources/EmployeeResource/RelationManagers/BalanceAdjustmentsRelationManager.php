<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Models\BalanceAdjustment;
use App\Models\Employee;
use App\Services\BalanceFormat;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Manual corrections to the overtime balance.
 *
 * Das Arbeitszeitkonto selbst bleibt schreibgeschützt — es ist das Ergebnis aus
 * Stempelungen und Vertrag. Altbestände, Übernahmen aus einem Vorsystem oder
 * eine vereinbarte Kappung werden daneben gebucht, mit Datum und Grund.
 */
class BalanceAdjustmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'balanceAdjustments';

    protected static ?string $title = 'Saldo-Korrekturen';

    /** Hours in the form, minutes in the column — nobody types four-digit minutes. */
    protected static function hoursInput(string $name, string $label): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($name)
            ->label($label)
            ->numeric()
            ->step(0.25)
            ->required()
            ->formatStateUsing(fn (?int $state) => $state === null ? null : round($state / 60, 2))
            ->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 60));
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('effective_date')
                ->label('Wirksam zum')
                ->required()
                ->default(now()->startOfYear())
                ->helperText('Bestimmt, in welches Jahr die Korrektur fällt und ab welchem '
                    .'Nachweis sie zu sehen ist.'),
            static::hoursInput('minutes', 'Korrektur (Stunden)')
                ->helperText('Minus baut ab, Plus schreibt gut. 1,5 = eineinhalb Stunden.'),
            Forms\Components\TextInput::make('note')
                ->label('Grund')
                ->maxLength(255)
                ->helperText('Warum wurde korrigiert? Steht später in der Liste.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_date', 'desc')
            ->emptyStateHeading('Keine Korrekturen')
            ->emptyStateDescription('Der Saldo ergibt sich allein aus dem Arbeitszeitkonto.')
            ->columns([
                Tables\Columns\TextColumn::make('effective_date')->label('Wirksam zum')
                    ->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('minutes')->label('Korrektur')
                    ->formatStateUsing(fn (int $state) => BalanceFormat::make($state))
                    ->color(fn (int $state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : 'gray'))
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('note')->label('Grund')->wrap()->placeholder('-'),
                Tables\Columns\TextColumn::make('creator.name')->label('Erfasst von')
                    ->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Erfasst am')
                    ->dateTime('d.m.Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                $this->setBalanceAction(),
                Tables\Actions\CreateAction::make()
                    ->label('Korrektur buchen')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * "Saldo zum Stichtag auf X setzen" — bucht die Differenz.
     *
     * Der gebräuchliche Fall ist ein Altbestand, der zum Jahreswechsel weg soll.
     * Die nötige Gegenbuchung von Hand auszurechnen ist fehleranfällig, also
     * rechnet die Aktion sie aus und zeigt sie an.
     */
    protected function setBalanceAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('setBalance')
            ->label('Saldo setzen')
            ->icon('heroicon-o-scale')
            ->color('warning')
            ->modalHeading('Saldo zum Stichtag festsetzen')
            ->modalDescription('Bucht die Differenz zwischen dem aktuellen Stand und dem '
                .'Zielwert. Das Arbeitszeitkonto selbst bleibt unverändert.')
            ->modalSubmitActionLabel('Differenz buchen')
            ->form([
                Forms\Components\DatePicker::make('effective_date')
                    ->label('Stichtag')
                    ->required()
                    ->default(now()->startOfYear())
                    ->live()
                    ->helperText(fn (Forms\Get $get) => $this->currentBalanceHint($get('effective_date'))),
                static::hoursInput('target', 'Zielsaldo (Stunden)')
                    ->default(0)
                    ->helperText('0 = Konto beginnt am Stichtag bei null.'),
                Forms\Components\TextInput::make('note')
                    ->label('Grund')
                    ->maxLength(255)
                    ->default('Altbestand bereinigt'),
            ])
            ->action(function (array $data): void {
                /** @var Employee $employee */
                $employee = $this->getOwnerRecord();
                $asOf = Carbon::parse($data['effective_date'])->startOfDay();

                // Der Stand zum Stichtag schließt bereits gebuchte Korrekturen
                // ein — zweimal "auf 0 setzen" darf nicht doppelt abziehen.
                $current = $employee->overtimeBalanceMinutes($asOf);
                $delta = (int) $data['target'] - $current;

                if ($delta === 0) {
                    Notification::make()
                        ->title('Nichts zu tun')
                        ->body('Der Saldo zum '.$asOf->format('d.m.Y').' entspricht bereits dem Zielwert.')
                        ->info()
                        ->send();

                    return;
                }

                BalanceAdjustment::create([
                    'employee_id' => $employee->id,
                    'effective_date' => $asOf->toDateString(),
                    'minutes' => $delta,
                    'note' => $data['note'] ?: null,
                    'created_by' => auth()->id(),
                ]);

                Notification::make()
                    ->title('Saldo gesetzt')
                    ->body('Gebucht: '.BalanceFormat::make($delta).' zum '.$asOf->format('d.m.Y').'.')
                    ->success()
                    ->send();
            });
    }

    /** Live hint in the modal: what the balance is at the chosen date. */
    protected function currentBalanceHint(mixed $date): string
    {
        if (! $date) {
            return 'Stichtag wählen, um den aktuellen Stand zu sehen.';
        }

        /** @var Employee $employee */
        $employee = $this->getOwnerRecord();
        $asOf = Carbon::parse($date)->startOfDay();

        return 'Aktueller Stand zum '.$asOf->format('d.m.Y').': '
            .BalanceFormat::make($employee->overtimeBalanceMinutes($asOf));
    }
}
