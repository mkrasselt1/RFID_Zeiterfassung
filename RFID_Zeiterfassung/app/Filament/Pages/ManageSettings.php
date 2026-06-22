<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ManagerOnly;
use App\Models\Setting;
use App\Services\GoogleCalendarApi;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;

/**
 * App settings (operator info, timezone, Google OAuth) plus the admin's own
 * profile/password. Replaces the legacy Settings.php + the config.php editor.
 */
class ManageSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use ManagerOnly;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Einstellungen';

    protected static ?string $title = 'Einstellungen';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.manage-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $operator = Setting::get('operator', []);
        $google = Setting::get('google', []);
        $admin = auth()->user();

        $this->form->fill([
            'admin_name' => $admin?->name,
            'admin_email' => $admin?->email,
            'admin_pwd_new' => null,
            'timezone' => Setting::get('timezone', 'Europe/Berlin'),
            'holiday_region' => Setting::get('holiday_region', 'DE-SN'),
            'operator_name' => $operator['name'] ?? '',
            'operator_address' => $operator['address'] ?? '',
            'operator_telephone' => $operator['telephone'] ?? '',
            'operator_email' => $operator['email'] ?? '',
            'google_clientId' => $google['clientId'] ?? '',
            'google_clientSecret' => $google['clientSecret'] ?? '',
            'google_redirectUrl' => $google['redirectUrl'] ?? url('/google/callback'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Profil')
                    ->schema([
                        TextInput::make('admin_name')->label('Name')->required(),
                        TextInput::make('admin_email')->label('E-Mail')->disabled(),
                        TextInput::make('admin_pwd_new')->label('Neues Passwort')
                            ->password()->minLength(6)->maxLength(25)
                            ->helperText('Leer lassen, um das Passwort nicht zu ändern.'),
                    ])->columns(2),
                Section::make('Allgemein')
                    ->schema([
                        TextInput::make('timezone')->label('Zeitzone')->required()
                            ->helperText('z. B. Europe/Berlin'),
                        \Filament\Forms\Components\Select::make('holiday_region')
                            ->label('Bundesland (Feiertage)')
                            ->options(\App\Services\HolidayService::REGIONS)
                            ->helperText('Gilt für die automatische Feiertags-Berechnung.'),
                    ])->columns(2),
                Section::make('Betreiber')
                    ->schema([
                        TextInput::make('operator_name')->label('Name'),
                        TextInput::make('operator_address')->label('Adresse'),
                        TextInput::make('operator_telephone')->label('Telefon'),
                        TextInput::make('operator_email')->label('E-Mail')->email(),
                    ])->columns(2),
                Section::make('Google Kalender')
                    ->description('OAuth-Zugangsdaten. Nach dem Speichern über den Button verbinden.')
                    ->schema([
                        TextInput::make('google_clientId')->label('Client ID'),
                        TextInput::make('google_clientSecret')->label('Client Secret')->password()->revealable(),
                        TextInput::make('google_redirectUrl')->label('Redirect URL')
                            ->helperText('Muss in der Google Cloud Console hinterlegt sein.'),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $admin = auth()->user();
        if ($admin) {
            $admin->name = $data['admin_name'];
            if (! empty($data['admin_pwd_new'])) {
                $admin->password = Hash::make($data['admin_pwd_new']);
            }
            $admin->save();
        }

        Setting::put('timezone', $data['timezone']);
        Setting::put('holiday_region', $data['holiday_region'] ?? 'DE-SN');
        Setting::put('operator', [
            'name' => $data['operator_name'],
            'address' => $data['operator_address'],
            'telephone' => $data['operator_telephone'],
            'email' => $data['operator_email'],
        ]);

        // Preserve any existing tokens; only overwrite the client config fields.
        $google = Setting::get('google', []);
        $google['clientId'] = $data['google_clientId'];
        $google['clientSecret'] = $data['google_clientSecret'];
        $google['redirectUrl'] = $data['google_redirectUrl'];
        Setting::put('google', $google);

        Notification::make()->title('Einstellungen gespeichert')->success()->send();
    }

    public function connectGoogle()
    {
        return redirect()->away(GoogleCalendarApi::make()->getOAuthUrl());
    }

    public function getGoogleConnected(): bool
    {
        return ! empty(Setting::get('google', [])['refreshToken'] ?? '');
    }
}
