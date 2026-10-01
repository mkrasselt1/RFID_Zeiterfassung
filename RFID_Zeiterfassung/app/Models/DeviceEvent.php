<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One buffered stamping delivered by a reader.
 *
 * Die Kennung `event_uid` kommt vom Gerät und macht die Lieferung
 * wiederholbar: dasselbe Ereignis zweimal geschickt wird einmal gebucht.
 */
class DeviceEvent extends Model
{
    protected $fillable = [
        'device_id', 'event_uid', 'card_uid',
        'occurred_at', 'received_at', 'status', 'message', 'user_log_id',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
