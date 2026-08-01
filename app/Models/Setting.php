<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $guarded = ['id'];
    public $timestamps = true;

    public static function get(string $key, $default = null)
    {
        $all = Cache::rememberForever('settings.all', function () {
            return self::pluck('value', 'key')->toArray();
        });

        return $all[$key] ?? $default;
    }

    public static function put(string $key, $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings.all');
    }
}
