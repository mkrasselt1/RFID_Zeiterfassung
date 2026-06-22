<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value app settings (operator info, timezone, Google OAuth config).
 * Replaces the legacy config.php. Values are JSON-encoded.
 */
class Setting extends Model
{
    protected $table = 'settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);

        return $row ? json_decode($row->value, true) : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => json_encode($value)],
        );
    }
}
