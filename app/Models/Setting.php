<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Small key/value settings edited from the dashboard (PO schedule, etc.). Read through a cache cleared on every write. */
class Setting extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever('settings', fn () => self::pluck('value', 'key')->all())[$key] ?? $default;
    }

    /** Saves all values or none; null removes the key so get() falls back to its default (the column is NOT NULL). */
    public static function put(array $values): void
    {
        DB::transaction(function () use ($values) {
            foreach ($values as $key => $value) {
                $value === null ? self::whereKey($key)->delete() : self::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });
        Cache::forget('settings');
    }
}
