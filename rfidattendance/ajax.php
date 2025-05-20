<?php
session_start();
header('Content-type: application/json');
$config = include "config.php";
require_once('google-calendar-api.php');

try {
	// Get event details
	$event = $_POST['event_details'];
	$cAPI = new GoogleCalendarApi(
		$config["google"]["clientId"],
		$config["google"]["clientSecret"],
		$config["google"]
	);
} catch (Exception $e) {
	header('Bad Request', true, 400);
	echo json_encode(array('error' => 1, 'message' => $e->getMessage()));
}
