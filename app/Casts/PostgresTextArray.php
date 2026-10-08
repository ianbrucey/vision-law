<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Postgres text[] cast (007). The documents.tags column is text[]; Laravel's
 * built-in 'array' cast writes JSON, which Postgres rejects as a malformed
 * array literal. This cast serializes to the {a,b} literal (quoting values
 * that need it) and parses it back to a list of strings.
 */
/**
 * @implements CastsAttributes<list<string>, list<string>|string|null>
 */
class PostgresTextArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get($model, string $key, $value, array $attributes): array
    {
        if ($value === null || $value === '{}') {
            return [];
        }

        if (is_array($value)) {
            return array_values($value);
        }

        $value = (string) $value;

        if (! str_starts_with($value, '{') || ! str_ends_with($value, '}')) {
            return [];
        }

        $inner = substr($value, 1, -1);

        if ($inner === '') {
            return [];
        }

        $out = [];
        $current = '';
        $inQuotes = false;
        $length = strlen($inner);

        for ($i = 0; $i < $length; $i++) {
            $ch = $inner[$i];

            if ($inQuotes) {
                if ($ch === '\\' && $i + 1 < $length) {
                    $current .= $inner[$i + 1];
                    $i++;
                } elseif ($ch === '"') {
                    $inQuotes = false;
                } else {
                    $current .= $ch;
                }
            } elseif ($ch === '"') {
                $inQuotes = true;
            } elseif ($ch === ',') {
                $out[] = $current;
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        $out[] = $current;

        return $out;
    }

    /**
     * @param  list<string>|string|null  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set($model, string $key, $value, array $attributes): string
    {
        if ($value === null) {
            return '{}';
        }

        $items = is_array($value) ? $value : [$value];

        $escaped = array_map(static function ($item): string {
            $item = (string) $item;

            // Bare words stay bare; anything with punctuation, quotes,
            // backslashes, or whitespace is double-quoted.
            if (preg_match('/^[A-Za-z0-9_]+$/', $item) === 1) {
                return $item;
            }

            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $item).'"';
        }, $items);

        return '{'.implode(',', $escaped).'}';
    }
}
