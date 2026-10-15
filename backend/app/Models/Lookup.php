<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Generic named list item (group + code). Lists are cached; flush on write. */
class Lookup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('hc.lookups'));
        static::deleted(fn () => Cache::forget('hc.lookups'));
    }

    /** All active items grouped: ['letter_kinds' => [['code'=>..,'name'=>..,'name_en'=>..,'meta'=>..], …]] */
    public static function grouped(): array
    {
        return Cache::rememberForever('hc.lookups', fn () => static::where('active', true)->orderBy('sort')->orderBy('id')
            ->get(['group', 'code', 'name', 'name_en', 'meta'])->groupBy('group')
            ->map(fn ($g) => $g->map(fn ($l) => ['code' => $l->code, 'name' => $l->name, 'name_en' => $l->name_en, 'meta' => $l->meta ?? []])->values()->all())->all());
    }

    public static function names(string $group): array
    {
        return array_column(static::grouped()[$group] ?? [], 'name');
    }

    public static function name(string $group, ?string $code): ?string
    {
        foreach (static::grouped()[$group] ?? [] as $l) {
            if ($l['code'] === $code) {
                return $l['name'];
            }
        }

        return $code;
    }
}
