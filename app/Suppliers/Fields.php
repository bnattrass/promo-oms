<?php

namespace App\Suppliers;

/**
 * Defensive field access for supplier payloads. Keys are matched case-insensitively and
 * ignoring underscores / dashes, so 'fullCode', 'FullCode' and 'full_code' all match.
 */
class Fields
{
    public static function norm(string $key): string
    {
        return strtolower(str_replace(['_', '-', ' '], '', $key));
    }

    /** First non-null value among candidate keys. Dot notation walks nested arrays. */
    public static function pick(?array $row, string ...$keys): mixed
    {
        if (! $row) {
            return null;
        }

        foreach ($keys as $key) {
            $value = self::get($row, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function get(array $row, string $path): mixed
    {
        $current = $row;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($current)) {
                return null;
            }
            $found = false;
            $wanted = self::norm($segment);
            foreach ($current as $k => $v) {
                if (is_string($k) && self::norm($k) === $wanted) {
                    $current = $v;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return null;
            }
        }

        return $current;
    }

    public static function string(?array $row, string ...$keys): ?string
    {
        $v = self::pick($row, ...$keys);

        return is_scalar($v) ? trim((string) $v) : null;
    }

    public static function int(?array $row, string ...$keys): ?int
    {
        $v = self::pick($row, ...$keys);

        return is_numeric($v) ? (int) $v : null;
    }

    public static function float(?array $row, string ...$keys): ?float
    {
        $v = self::pick($row, ...$keys);

        return is_numeric($v) ? (float) $v : null;
    }

    /** A list field; also accepts a comma-separated string ("A,B,C"). */
    public static function list(?array $row, string ...$keys): array
    {
        $v = self::pick($row, ...$keys);
        if (is_string($v)) {
            return array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
        }

        return is_array($v) ? array_values($v) : [];
    }
}
