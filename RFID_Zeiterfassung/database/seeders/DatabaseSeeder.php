<?php

namespace Database\Seeders;

use App\Models\Absence;
use App\Models\Cardholder;
use App\Models\Contract;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Employee::updateOrCreate(
            ['email' => 'admin@example.de'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'role' => Employee::ROLE_ADMIN,
                'is_active' => true,
            ],
        );

        $employee = Employee::updateOrCreate(
            ['email' => 'max@example.de'],
            [
                'name' => 'Max Mustermann',
                'password' => Hash::make('password'),
                'role' => Employee::ROLE_EMPLOYEE,
                'personnel_number' => '1001',
                'supervisor_id' => $admin->id,
                'is_active' => true,
            ],
        );

        Contract::updateOrCreate(
            ['employee_id' => $employee->id, 'valid_from' => '2024-01-01'],
            [
                'title' => 'Vollzeit',
                'valid_to' => null,
                'worktime_model' => Contract::MODEL_DAILY,
                'target_hours' => 8,
                'workdays' => [1, 2, 3, 4, 5],
                'vacation_days_per_year' => 30,
            ],
        );

        Setting::put('timezone', 'Europe/Berlin');
        Setting::put('operator', [
            'name' => 'Company name',
            'address' => 'Company address',
            'telephone' => '',
            'email' => 'info@example.de',
        ]);
        Setting::put('google', [
            'clientId' => '',
            'clientSecret' => '',
            'redirectUrl' => '',
            'accessToken' => '',
            'refreshToken' => '',
            'expiration' => 0,
        ]);

        $device = Device::updateOrCreate(
            ['device_uid' => 'a1b2c3d4e5f60718'],
            [
                'device_name' => 'Haupteingang',
                'device_dep' => 'Buero',
                'device_date' => now()->toDateString(),
                'device_mode' => Device::MODE_TIME,
            ],
        );

        Device::updateOrCreate(
            ['device_uid' => '00112233445566ff'],
            [
                'device_name' => 'Registrierung',
                'device_dep' => 'Buero',
                'device_date' => now()->toDateString(),
                'device_mode' => Device::MODE_LEARN,
            ],
        );

        // Two cards for the same employee (demonstrates multiple cards each).
        foreach (['deadbeef', 'beefcafe'] as $uid) {
            Cardholder::updateOrCreate(
                ['card_uid' => $uid],
                [
                    'username' => $employee->name,
                    'serialnumber' => 1001,
                    'gender' => 'Male',
                    'email' => $employee->email,
                    'add_card' => 1,
                    'card_select' => 0,
                    'device_dep' => 'Buero',
                    'device_uid' => $device->device_uid,
                    'user_date' => now()->toDateString(),
                    'employee_id' => $employee->id,
                ],
            );
        }

        Absence::firstOrCreate(
            [
                'employee_id' => $employee->id,
                'type' => Absence::TYPE_VACATION,
                'start_date' => now()->addWeek()->toDateString(),
            ],
            [
                'end_date' => now()->addWeek()->addDays(4)->toDateString(),
                'status' => Absence::STATUS_PENDING,
                'reason' => 'Sommerurlaub',
            ],
        );
    }
}
