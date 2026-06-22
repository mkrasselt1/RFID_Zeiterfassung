<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CardsRelationManager extends RelationManager
{
    protected static string $relationship = 'cards';

    protected static ?string $title = 'Karten';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('card_uid')
                ->label('Karten-UID')
                ->helperText('Hex, Großbuchstaben (Format wie vom Lesegerät).')
                ->required()
                ->maxLength(30),
            Forms\Components\TextInput::make('device_dep')
                ->label('Abteilung')
                ->helperText("Abteilung oder 'All'.")
                ->maxLength(20),
            Forms\Components\Toggle::make('add_card')->label('Registriert')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('card_uid')
            ->columns([
                Tables\Columns\TextColumn::make('card_uid')->label('Karten-UID')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('device_dep')->label('Abteilung'),
                Tables\Columns\IconColumn::make('add_card')->label('Registriert')->boolean(),
                Tables\Columns\TextColumn::make('user_date')->label('Erfasst')->date('d.m.Y'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        // Normalize to the firmware UID format and fill legacy defaults.
                        $data['card_uid'] = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', $data['card_uid'] ?? ''));
                        $data['username'] = $this->getOwnerRecord()->name;
                        $data['email'] = $this->getOwnerRecord()->email;
                        $data['serialnumber'] = $this->getOwnerRecord()->personnel_number ?? 0;
                        $data['user_date'] = now()->toDateString();
                        $data['device_uid'] = $data['device_uid'] ?? 'Web';

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
}
