<?php

use App\Http\Controllers\AdminApiController;
use App\Http\Controllers\DeviceApiController;
use App\Http\Controllers\DeviceStampingController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

// Per-employee monthly worktime report as PDF (auth required; access checked
// in the controller — managers any employee, employees only themselves).
Route::middleware('auth')->get(
    '/reports/worktime/{employee}/{year}/{month}',
    [ReportController::class, 'worktimePdf'],
)->name('reports.worktime');

// Device-facing RFID endpoint. Kept at the legacy /getdata.php path so existing
// ESP32 firmware (which targets <server>/getdata.php) works without reflashing.
Route::get('/getdata.php', [DeviceApiController::class, 'handle']);
Route::get('/getdata', [DeviceApiController::class, 'handle']);

// Gepufferte Stempelungen: mehrere auf einmal, mit Gerätezeit und einer Kennung
// je Ereignis, damit ein Wiederholungsversuch nichts doppelt bucht. Ohne
// CSRF-Prüfung und ohne Session — ein Leser hat beides nicht; er weist sich
// über sein Token im Authorization-Header aus.
Route::post('/api/v1/stampings', [DeviceStampingController::class, 'store'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('api.stampings');

// Stempeln ohne Karte: Namen am Drehrad wählen, PIN eingeben. Eigener Pfad,
// weil hier anders geprüft wird — und weil die PIN nur online geht, während
// der Weg oben auch ohne Netz trägt.
Route::prefix('api/v1/kiosk')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->group(function () {
        Route::get('/employees', [KioskController::class, 'employees'])->name('api.kiosk.employees');
        Route::post('/stampings', [KioskController::class, 'store'])->name('api.kiosk.stampings');
    });

// Admin-API zum Nachtragen von Stempelungen und Abwesenheiten (z.B. aus alten
// Stundenzetteln). Basic Auth mit einem HR-/Admin-Konto, ohne Sitzung.
Route::prefix('api/v1/admin')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->middleware([\App\Http\Middleware\AdminApiAuth::class, 'throttle:600,1'])
    ->group(function () {
        Route::get('/employees', [AdminApiController::class, 'employees'])->name('api.admin.employees');
        Route::get('/stampings', [AdminApiController::class, 'stampings'])->name('api.admin.stampings');
        Route::post('/stampings', [AdminApiController::class, 'storeStamping'])->name('api.admin.stampings.store');
        Route::patch('/stampings/{log}', [AdminApiController::class, 'updateStamping'])->name('api.admin.stampings.update');
        Route::delete('/stampings/{log}', [AdminApiController::class, 'destroyStamping'])->name('api.admin.stampings.destroy');
        Route::get('/absences', [AdminApiController::class, 'absences'])->name('api.admin.absences');
        Route::post('/absences', [AdminApiController::class, 'storeAbsence'])->name('api.admin.absences.store');
    });

// Google OAuth callback (replaces legacy google-login.php).
Route::get('/google/connect', [GoogleOAuthController::class, 'connect'])->name('google.connect');
Route::get('/google/callback', [GoogleOAuthController::class, 'callback'])->name('google.callback');
