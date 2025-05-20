<?php
session_start();
$config = include "config.php";
include "google-calendar-api.php";
if (!isset($_SESSION['access_token'])) {
    header('Location: google-login.php');
    exit();
}

?>
<!DOCTYPE html>
<html>

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/jquery-datetimepicker/2.1.9/jquery.datetimepicker.min.css" />
    <script src="//ajax.googleapis.com/ajax/libs/jquery/2.2.4/jquery.min.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/jquery-datetimepicker/2.1.9/jquery.datetimepicker.min.js"></script>
    <style type="text/css">
        #form-container {
            width: 400px;
            margin: 100px auto;
        }

        input[type="text"] {
            border: 1px solid rgba(0, 0, 0, 0.15);
            font-family: inherit;
            font-size: inherit;
            padding: 8px;
            border-radius: 0px;
            outline: none;
            display: block;
            margin: 0 0 20px 0;
            width: 100%;
            box-sizing: border-box;
        }

        select {
            border: 1px solid rgba(0, 0, 0, 0.15);
            font-family: inherit;
            font-size: inherit;
            padding: 8px;
            border-radius: 2px;
            display: block;
            width: 100%;
            box-sizing: border-box;
            outline: none;
            background: none;
            margin: 0 0 20px 0;
        }

        .input-error {
            border: 1px solid red !important;
        }

        #event-date {
            display: none;
        }

        #create-update-event {
            background: none;
            width: 100%;
            display: block;
            margin: 0 auto;
            border: 2px solid #2980b9;
            padding: 8px;
            background: none;
            color: #2980b9;
            cursor: pointer;
        }

        #delete-event {
            background: none;
            width: 100%;
            display: block;
            margin: 20px auto 0 auto;
            border: 2px solid #2980b9;
            padding: 8px;
            background: none;
            color: #2980b9;
            cursor: pointer;
        }
    </style>
</head>

<body>
    
    

</body>

</html>