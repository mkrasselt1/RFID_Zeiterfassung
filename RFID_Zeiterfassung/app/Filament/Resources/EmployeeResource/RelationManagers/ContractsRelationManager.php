<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Models\Contract;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    protected static ?string $title = 'Verträge';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label('Bezeichnung'),
            Forms\Components\DatePicker::make('valid_from')->label('Gültig ab')->required()->default(now()),
            Forms\Components\DatePicker::make('valid_to')->label('Gültig bis')
                ->helperText('Leer = unbefristet.'),
            Forms\Components\Select::make('worktime_model')->label('Arbeitszeit-Modell')
                ->options(Contract::MODELS)
                ->default(Contract::MODEL_DAILY)
                ->live()
                ->required(),
            Forms\Components\TextInput::make('target_hours')->label('Sollstunden')
                ->numeric()->step(0.25)
                ->helperText('Je nach Modell: pro Woche / pro Monat / pro Arbeitstag.')
                ->visible(fn (Forms\Get $get) => $get('worktime_model') !== Contract::MODEL_TRACKING),
            Forms\Components\CheckboxList::make('workdays')->label('Arbeitstage')
                ->options([
                    1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So',
                ])
                ->default([1, 2, 3, 4, 5])
                ->columns(7)
                ->visible(fn (Forms\Get $get) => $get('worktime_model') !== Contract::MODEL_TRACKING),
            Forms\Components\TextInput::make('vacation_days_per_year')->label('Urlaubstage / Jahr')
                ->numeric()->step(0.5),
            Forms\Components\Fieldset::make('Pausen')
                ->schema([
                    Forms\Components\Toggle::make('has_break_override')
                        ->label('Eigene Pausenstaffel')
                        ->helperText('Aus = globale Staffel aus den Einstellungen ('.static::globalRuleSummary().').')
                        ->live()
                        ->dehydrated(false)
                        ->afterStateHydrated(fn (Forms\Components\Toggle $component, ?Contract $record) => $component
                            ->state((bool) $record?->break_rules)),
                    Forms\Components\Repeater::make('break_rules')
                        ->label('Staffel')
                        ->schema([
                            Forms\Components\TextInput::make('from_minutes')->label('Ab Arbeitszeit (Min.)')
                                ->numeric()->required()->minValue(0),
                            Forms\Components\TextInput::make('minutes')->label('Pause (Min.)')
                                ->numeric()->required()->minValue(1),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Stufe hinzufügen')
                        ->visible(fn (Forms\Get $get) => (bool) $get('has_break_override'))
                        // Always dehydrated: toggling the override off must clear a
                        // previously stored staircase, and hidden fields are skipped
                        // by default. null -> the global default applies.
                        ->dehydrated(true)
                        ->dehydrateStateUsing(fn (?array $state, Forms\Get $get) => $get('has_break_override')
                            ? Contract::normalizeBreakRules($state)
                            : null),
                ])->columns(1),
        ])->columns(2);
    }

    /** Human-readable global staircase, e.g. "ab 6:00 → 30 min, ab 9:00 → 45 min". */
    protected static function globalRuleSummary(): string
    {
        return collect(Contract::globalBreakRules())
            ->map(fn (array $r) => sprintf(
                'ab %d:%02d → %d min',
                intdiv($r['from_minutes'], 60), $r['from_minutes'] % 60, $r['minutes'],
            ))
            ->implode(', ');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('valid_from', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Bezeichnung'),
                Tables\Columns\TextColumn::make('valid_from')->label('Ab')->date('d.m.Y'),
                Tables\Columns\TextColumn::make('valid_to')->label('Bis')->date('d.m.Y')->placeholder('unbefristet'),
                Tables\Columns\TextColumn::make('worktime_model')->label('Modell')->badge()
                    ->formatStateUsing(fn (string $state) => Contract::MODELS[$state] ?? $state),
                Tables\Columns\TextColumn::make('target_hours')->label('Soll')->placeholder('-'),
                Tables\Columns\TextColumn::make('vacation_days_per_year')->label('Urlaub/J')->placeholder('-'),
                Tables\Columns\TextColumn::make('break_rules')->label('Pausen')
                    ->formatStateUsing(fn (?array $state) => $state
                        ? collect($state)->map(fn ($r) => $r['minutes'].' min')->implode(' / ')
                        : 'global')
                    ->color(fn (?array $state) => $state ? null : 'gray'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
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
}
