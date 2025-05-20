<?php
// filepath: app/Models/UserLog.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserLog extends Model
{
    use HasFactory;

    protected $table = 'users_logs';
    protected $fillable = [
        'username',
        'serialnumber',
        'card_uid',
        'device_uid',
        'device_dep',
        'checkindate',
        'timein',
        'timeout',
        'calendarEventId',
        'card_out',
    ];
}