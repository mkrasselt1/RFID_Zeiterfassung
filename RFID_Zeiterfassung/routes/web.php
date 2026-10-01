<?php

use App\Http\Controllers\DeviceApiController;
use App\Http\Controllers\DeviceStampingController;
use App\Http\Controllers\GoogleOAuthController;
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

// Google OAuth callback (replaces legacy google-login.php).
Route::get('/google/connect', [GoogleOAuthController::class, 'connect'])->name('google.connect');
Route::get('/google/callback', [GoogleOAuthController::class, 'callback'])->name('google.callback');
