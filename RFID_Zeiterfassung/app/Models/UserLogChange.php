<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ein Eintrag im Änderungsprotokoll der Stempelungen (siehe UserLogObserver). */
class UserLogChange extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    public const ACTIONS = [
        self::ACTION_CREATED => 'Angelegt',
        self::ACTION_UPDATED => 'Geändert',
        self::ACTION_DELETED => 'Gelöscht',
    ];

    public const CHANNEL_PANEL = 'panel';

    public const CHANNEL_API = 'api';

    protected $fillable = [
        'user_log_id', 'employee_id', 'action', 'before', 'after', 'changed_by', 'channel',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }
}
