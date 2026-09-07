<?php

declare(strict_types=1);

namespace App\Support;

final class Env
{
    /**
     * Parse .env-style content into a key/value array.
     *
     * - Lines starting with '#' are comments.
     * - Values may optionally be wrapped in single or double quotes.
     * - Lines without '=' are ignored.
     */
    public static function parse(string $content): array
    {
        $values = [];

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            $value = trim($value, "\"'");

            if ($key !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
