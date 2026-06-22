<?php

namespace App\Services;

use App\Models\Setting;
use Exception;

/**
 * Port of the legacy GoogleCalendarApi (rfidattendance/google-calendar-api.php).
 * Behaviour and Google endpoints are unchanged; OAuth config now lives in the
 * `settings` table ("google" key) instead of a rewritten config.php.
 *
 * Build via make() and call persist() after operations so a refreshed access
 * token is saved back, mirroring the legacy file_put_contents(config.php) step.
 */
class GoogleCalendarApi
{
    protected string $clientId = '';
    protected string $clientSecret = '';
    protected array $options = [];

    protected string $refreshToken = '';
    protected string $accessToken = '';
    protected int $expiration = 0;
    public bool $tokenUpdated = false;

    public function __construct(string $clientId, string $clientSecret, array $options)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->options = $options;
        if (count($options)) {
            $this->setRefreshToken(
                $options['accessToken'] ?? '',
                $options['refreshToken'] ?? '',
                $options['expiration'] ?? 0,
            );
        }
    }

    /** Build the API from the stored "google" setting. */
    public static function make(): self
    {
        $google = Setting::get('google', []);

        return new self(
            $google['clientId'] ?? '',
            $google['clientSecret'] ?? '',
            $google,
        );
    }

    /** Persist refreshed tokens back to settings (replaces config.php rewrite). */
    public function persist(): void
    {
        if ($this->tokenUpdated) {
            Setting::put('google', $this->getConfig());
            $this->tokenUpdated = false;
        }
    }

    private function curl(string $url, string $curlPost, int &$returnCode, string $requestType = ''): null|array|int|object|string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if (! empty($curlPost)) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $curlPost);
        }
        if (! empty($requestType)) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestType);
        }
        if (! empty($this->accessToken)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->accessToken]);
        }
        $data = curl_exec($ch);
        $returnCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (json_decode($data)) {
            $data = json_decode($data, true);
        }

        return $data;
    }

    public function getOAuthUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/auth?' .
            'client_id=' . $this->clientId .
            '&access_type=' . 'offline' .
            '&prompt=' . 'consent' .
            '&scope=' . urlencode('https://www.googleapis.com/auth/calendar') .
            '&redirect_uri=' . urlencode($this->options['redirectUrl'] ?? '') .
            '&response_type=' . 'code';
    }

    public function setRefreshToken($accessToken, $refreshToken, $expiration): void
    {
        $this->refreshToken = $refreshToken;
        $this->expiration = $expiration;
        $this->accessToken = $accessToken;
    }

    public function refreshAccessToken(): void
    {
        if (time() >= ($this->expiration - 100)) {
            $data = $this->GetRefreshAccessToken();
            $this->accessToken = $data['access_token'];
            $this->expiration = time() + ($data['expires_in'] ?? 100);
            $this->tokenUpdated = true;
        }
    }

    public function getConfig(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'redirectUrl' => $this->options['redirectUrl'] ?? '',
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'expiration' => $this->expiration,
        ];
    }

    public function GetAccessToken($code)
    {
        $http_code = 0;
        $this->accessToken = '';
        $data = $this->curl(
            'https://accounts.google.com/o/oauth2/token',
            'client_id=' . $this->clientId .
                '&redirect_uri=' . ($this->options['redirectUrl'] ?? '') .
                '&client_secret=' . $this->clientSecret .
                '&code=' . $code .
                '&grant_type=authorization_code',
            $http_code,
        );
        if ($http_code != 200) {
            throw new Exception('Error : Failed to receive access token');
        }
        $this->expiration = time() + $data['expires_in'];
        $this->accessToken = $data['access_token'];

        if (isset($data['refresh_token']) && $data['refresh_token'] != $this->refreshToken) {
            $this->refreshToken = $data['refresh_token'];
        }
        $this->tokenUpdated = true;

        return $data;
    }

    public function GetRefreshAccessToken()
    {
        $http_code = 0;
        $this->accessToken = '';
        $data = $this->curl(
            'https://accounts.google.com/o/oauth2/token',
            http_build_query([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
                'grant_type' => 'refresh_token',
            ]),
            $http_code,
        );
        if ($http_code != 200) {
            throw new Exception('Error : Failed to refresh access token');
        }

        return $data;
    }

    public function GetUserCalendarTimezone()
    {
        $this->refreshAccessToken();
        $http_code = 0;
        $data = $this->curl(
            'https://www.googleapis.com/calendar/v3/users/me/settings/timezone',
            '',
            $http_code,
        );
        if ($http_code != 200) {
            throw new Exception('Error : Failed to get timezone');
        }

        return $data['value'];
    }

    public function GetCalendarsList()
    {
        if (empty($this->clientId) && empty($this->refreshToken)) {
            return [];
        }
        try {
            $this->refreshAccessToken();
        } catch (Exception $e) {
            return [];
        }
        $http_code = 0;
        $data = $this->curl(
            'https://www.googleapis.com/calendar/v3/users/me/calendarList?' . http_build_query([
                'fields' => 'items(id,summary,timeZone)',
                'minAccessRole' => 'owner',
            ]),
            '',
            $http_code,
        );
        if ($http_code != 200) {
            throw new Exception('Error : Failed to get calendars list');
        }

        return $data['items'];
    }

    public function CreateCalendarEvent($calendarId, $summary, $allDay, $recurrence, $recurrenceEnd, $eventTime, $eventTimezone): ?string
    {
        try {
            $this->refreshAccessToken();
        } catch (Exception $e) {
            return null;
        }
        $curlPost = ['summary' => $summary];

        if ($allDay == 1) {
            $curlPost['start'] = ['date' => $eventTime['event_date']];
            $curlPost['end'] = ['date' => $eventTime['event_date']];
        } else {
            $curlPost['start'] = ['dateTime' => $eventTime['start_time'], 'timeZone' => $eventTimezone];
            $curlPost['end'] = ['dateTime' => $eventTime['end_time'], 'timeZone' => $eventTimezone];
        }

        if ($recurrence == 1) {
            $curlPost['recurrence'] = ['RRULE:FREQ=WEEKLY;UNTIL=' . str_replace('-', '', $recurrenceEnd) . ';'];
        }
        $http_code = 0;
        $data = $this->curl(
            'https://www.googleapis.com/calendar/v3/calendars/' . $calendarId . '/events',
            json_encode($curlPost),
            $http_code,
        );
        if ($http_code != 200) {
            throw new Exception('Error : Failed to create event:' . var_export($data, true));
        }

        return $data['id'];
    }

    public function UpdateCalendarEvent($eventId, $calendarId, $summary, $allDay, $eventTime, $eventTimezone)
    {
        try {
            $this->refreshAccessToken();
        } catch (Exception $e) {
            return;
        }
        $curlPost = ['summary' => $summary];
        if ($allDay == 1) {
            $curlPost['start'] = ['date' => $eventTime['event_date']];
            $curlPost['end'] = ['date' => $eventTime['event_date']];
        } else {
            $curlPost['start'] = ['dateTime' => $eventTime['start_time'], 'timeZone' => $eventTimezone];
            $curlPost['end'] = ['dateTime' => $eventTime['end_time'], 'timeZone' => $eventTimezone];
        }
        $http_code = 0;
        $data = $this->curl(
            'https://www.googleapis.com/calendar/v3/calendars/' . $calendarId . '/events/' . $eventId,
            json_encode($curlPost),
            $http_code,
            'PUT',
        );
        if ($http_code != 200) {
            throw new Exception('Error : Failed to update event');
        }

        return $data;
    }

    public function DeleteCalendarEvent($eventId, $calendarId)
    {
        $this->refreshAccessToken();
        $http_code = 0;
        $data = $this->curl(
            'https://www.googleapis.com/calendar/v3/calendars/' . $calendarId . '/events/' . $eventId,
            '',
            $http_code,
            'DELETE',
        );
        if ($http_code != 204) {
            throw new Exception('Error : Failed to delete event');
        }

        return $data;
    }
}
