<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class CsvSafe
{
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $text = Text::truncate((string) $value, 10000);
        $trimmed = ltrim($text);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $text = "'" . $text;
        }
        return $text;
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function render(array $rows, array $columns): string
    {
        $stream = fopen('php://temp', 'w+b');
        if (!is_resource($stream)) {
            throw new \RuntimeException('CSV buffer could not be created.');
        }
        fputcsv($stream, array_map([self::class, 'cell'], $columns));
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = self::cell($row[$column] ?? null);
            }
            fputcsv($stream, $line);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($csv)) {
            throw new \RuntimeException('CSV generation failed.');
        }
        return "\xEF\xBB\xBF" . $csv;
    }
}
