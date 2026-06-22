<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ManagerOnly;
use App\Filament\Resources\HolidayResource\Pages;
use App\Models\Holiday;
use App\Services\HolidayService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HolidayResource extends Resource
{
    use ManagerOnly;

    protected static ?string $model = Holiday::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Feiertage';

    protected static ?string $modelLabel = 'Feiertag';

    protected static ?string $pluralModelLabel = 'Feiertage';

    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('date')->label('Datum')->required()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('name')->label('Bezeichnung')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Datum')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Bezeichnung')->searchable(),
                Tables\Columns\TextColumn::make('source')->label('Quelle')->badge()
                    ->formatStateUsing(fn (string $state) => $state === Holiday::SOURCE_AUTO ? 'Automatisch' : 'Manuell')
                    ->color(fn (string $state) => $state === Holiday::SOURCE_AUTO ? 'gray' : 'info'),
            ])
            ->filters([
                Tables\Filters\Filter::make('year')
                    ->form([Forms\Components\TextInput::make('year')->numeric()->placeholder((string) now()->year)])
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $query, array $data) => $query->when($data['year'], fn ($query, $y) => $query->whereYear('date', $y))),
            ])
            ->headerActions([
                Tables\Actions\Action::make('import')
                    ->label('Feiertage importieren')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->form([
                        Forms\Components\TextInput::make('year')->label('Jahr')->numeric()
                            ->default(now()->year)->required(),
                        Forms\Components\Select::make('region')->label('Bundesland')
                            ->options(HolidayService::REGIONS)
                            ->default(HolidayService::region())
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $count = app(HolidayService::class)->sync((int) $data['year'], $data['region']);
                        Notification::make()
                            ->title("{$count} Feiertage importiert")
                            ->body('Tipp: anschließend das Arbeitszeitkonto neu berechnen.')
                            ->success()->send();
                    }),
                Tables\Actions\CreateAction::make()->mutateFormDataUsing(function (array $data) {
                    $data['source'] = Holiday::SOURCE_MANUAL;

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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHolidays::route('/'),
        ];
    }
}
