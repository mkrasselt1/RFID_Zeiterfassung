<?php
session_start();
require 'connectDB.php';
include "./google-calendar-api.php";

if (!isset($_SESSION['Admin-name'])) {
    header("location: login.php");
}

$cAPI = new GoogleCalendarApi(
    $config["google"]["clientId"],
    $config["google"]["clientSecret"],
    $config["google"]
);
$d = date("Y-m-d");
$t = date("H:i:s");

$userEMail = $_SESSION['Admin-email'];

// Get user details from the database
$user = getUserByEmail($userEMail);

// Check if the user is already checked in
$Log = getLogByCheckinDate($d, $user->card_uid);
$isCheckedIn = !is_null($Log);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];

    if ($action == 'checkin' && !$isCheckedIn) {
        // Check-in Logik hier
        $eventId = null;
        if (!empty($user->calendarId)) {
            $eventId = $cAPI->CreateCalendarEvent(
                $user->calendarId,
                $user->username . " Arbeitszeit",
                false,
                false,
                false,
                [
                    "start_time" => (new DateTime())->format(\DateTime::RFC3339),
                    "end_time" => (new DateTime())->modify("+5 minutes")->format(\DateTime::RFC3339)
                ],
                $config["timezone"]
            );
        }
        $Log = new UserLogObject([
            "username" => $user->username,
            "serialnumber" => $user->serialnumber,
            "card_uid" => $user->card_uid,
            "device_uid" => "Web",
            "device_dep" => "Web",
            "checkindate" => $d,
            "timein" => $t,
            "timeout" => 0,
            "calendarEventId" => $eventId
        ], $conn);

        if (!$Log->insert()){
            echo "Error: SQL Checkin Fehler";
        }
    } else if ($action == 'checkout' && $isCheckedIn) {
        // Check-out Logik hier
        if (!empty($Log->calendarEventId)) {
            $cAPI->UpdateCalendarEvent(
                $Log->calendarEventId,
                $user->calendarId,
                $user->username . " Arbeitszeit",
                false,
                [
                    "start_time" => (new DateTime($Log->checkindate . " " . $Log->timein))->format(\DateTime::RFC3339),
                    "end_time" => (new DateTime())->format(\DateTime::RFC3339)
                ],
                $config["timezone"]
            );
        }
        $Log->timeout = $t;
        $Log->card_out = 1;
        if (!$Log->save()) {
            echo "Error: SQL Checkout Fehler";
        }
    }
}
//recheck after eventual checkin/out
$Log = getLogByCheckinDate($d, $user->card_uid);
$isCheckedIn = !is_null($Log);


//Output Stage
$title = "Ein-/Auschecken";
ob_start();
?>
<section class="container my-5">
    <h2>Ein-/Auschecken</h2>
    <form method="post">
        <button type="submit" name="action" value="checkin" class="btn btn-success" <?php if ($isCheckedIn) echo 'disabled'; ?>>Einchecken</button>
        <button type="submit" name="action" value="checkout" class="btn btn-danger" <?php if (!$isCheckedIn) echo 'disabled'; ?>>Auschecken</button>
    </form>
</section>
<?php
$html = ob_get_clean();
include "template/index.phtml";
?>