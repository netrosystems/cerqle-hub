<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'is_secret', 'group'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }

    public function getValueAttribute($value): ?string
    {
        $raw = $this->attributes['value'] ?? $value;
        if ($this->is_secret && $raw) {
            try {
                return Crypt::decryptString($raw);
            } catch (\Throwable) {
                // Older saves could mark a setting as secret after assigning
                // the value, leaving plaintext in the DB. Keep those settings
                // readable so integrations do not appear "not configured";
                // the next save will re-encrypt via setValueAttribute().
                return $raw;
            }
        }

        return $raw;
    }

    public function setValueAttribute($value): void
    {
        if ($this->is_secret && $value !== null && $value !== '') {
            $this->attributes['value'] = Crypt::encryptString($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }

    public static function get(string $key, $default = null)
    {
        $s = static::where('key', $key)->first();

        return $s ? $s->value : $default;
    }

    /**
     * Resolve several settings with a single database query.
     *
     * Calling get() in a loop turns large settings-driven pages into hundreds
     * of queries. Accepting the defaults as a keyed array preserves the same
     * fallback behaviour while keeping the request cost constant.
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function getMany(array $defaults): array
    {
        if ($defaults === []) {
            return [];
        }

        $stored = static::query()
            ->whereIn('key', array_keys($defaults))
            ->get()
            ->keyBy('key');

        $resolved = [];
        foreach ($defaults as $key => $default) {
            $resolved[$key] = $stored->has($key)
                ? $stored->get($key)->value
                : $default;
        }

        return $resolved;
    }

    public static function set(string $key, $value, bool $isSecret = false, ?string $group = null): void
    {
        $s = static::firstOrNew(['key' => $key]);
        $s->is_secret = $isSecret;
        $s->value = $value;
        $s->group = $group;
        $s->save();
    }
}
