<?php
//Connect to database
require 'connectDB.php';
include "./google-calendar-api.php";
$cAPI = new GoogleCalendarApi($config["google"]["clientId"], $config["google"]["clientSecret"], $config["google"]);

$logsWithoutCalendar = getLogListWithoutCalendar();
$limit = 100;
foreach ($logsWithoutCalendar as $Log) {
    $user = getUserByCardId($Log->card_uid);
    if (new DateTime($Log->checkindate . " " . $Log->timein) > new DateTime($Log->checkindate . " " . $Log->timeout)) {
        $checkoutDate = (new DateTime($Log->checkindate . " " . $Log->timeout))->modify("+1 day");
    }else{
        $checkoutDate = (new DateTime($Log->checkindate . " " . $Log->timeout));
    }
    if (!empty($user->calendarId)) {
        $eventId = $cAPI->CreateCalendarEvent(
            $user->calendarId,
            $user->username . " Arbeitszeit",
            false,
            false,
            false,
            [
                "start_time" => (new DateTime($Log->checkindate . " " . $Log->timein))->format(\DateTime::RFC3339),
                "end_time" =>  $checkoutDate?->format(\DateTime::RFC3339) ?? (new DateTime($Log->checkindate . " " . $Log->timeout))->format(\DateTime::RFC3339),
            ],
            $config["timezone"]
        );
        $Log->calendarEventId = $eventId;
        if (!$Log->save()) {
            error("Error: SQL Checkout Fehler");
        }
        $limit--;
        if(!$limit)
            exit;
        sleep(1); //rate limit for google api
    }
}
if ($cAPI->tokenUpdated) {
    $config["google"] = $cAPI->getConfig();
    file_put_contents(
        "./config.php",
        "<?php\n\rreturn " . var_export($config, true) . ";\n?>"
    );
}
