<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\GoogleCalendarApi;
use Exception;
use Illuminate\Http\Request;

/**
 * Google Calendar OAuth flow. Replaces the legacy google-login.php: "connect"
 * sends the admin to Google's consent screen, "callback" exchanges the code for
 * tokens and stores them in settings.
 */
class GoogleOAuthController extends Controller
{
    public function connect()
    {
        return redirect()->away(GoogleCalendarApi::make()->getOAuthUrl());
    }

    public function callback(Request $request)
    {
        if (! $request->filled('code')) {
            return redirect('/admin');
        }

        $cAPI = GoogleCalendarApi::make();
        try {
            $cAPI->GetAccessToken($request->query('code'));
            Setting::put('google', $cAPI->getConfig());
        } catch (Exception $e) {
            return response($e->getMessage(), 400);
        }

        return redirect('/admin');
    }
}
