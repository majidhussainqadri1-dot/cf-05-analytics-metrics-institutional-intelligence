<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class Json
{
    public static function encode(mixed $value): string
    {
        $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('JSON encoding failed.');
        }
        return $json;
    }

    /** @return array<string,mixed> */
    public static function object(string $json): array
    {
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }

    /** @return array<int,mixed> */
    public static function list(string $json): array
    {
        $value = json_decode($json, true);
        return is_array($value) ? array_values($value) : [];
    }

    public static function canonical(mixed $value): string
    {
        return self::encode(self::sortRecursive($value));
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'sortRecursive'], $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursive($item);
        }
        return $value;
    }
}
