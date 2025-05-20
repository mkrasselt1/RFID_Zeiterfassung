<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserLog;

class CheckinController extends Controller
{
    public function index()
    {
        // Logik für Check-in/Check-out
        return view('checkin');
    }

    public function checkin(Request $request)
    {
        // Check-in-Logik hier
    }

    public function checkout(Request $request)
    {
        // Check-out-Logik hier
    }
}