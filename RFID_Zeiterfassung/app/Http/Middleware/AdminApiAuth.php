<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-API: Anmeldung mit E-Mail und Passwort eines Panel-Kontos per HTTP
 * Basic Auth, je Anfrage und ohne Sitzung. Nur HR und Admin kommen durch —
 * dieselben, die im Panel Stempelungen korrigieren dürfen.
 */
class AdminApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $denied = Auth::onceBasic('email');
        if ($denied !== null) {
            return response()->json(['error' => 'Anmeldung fehlgeschlagen'], 401, ['WWW-Authenticate' => 'Basic']);
        }

        /** @var Employee $user */
        $user = Auth::user();
        if (! $user->is_active || ! $user->canManagePeople()) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        return $next($request);
    }
}
