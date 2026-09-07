<?php

declare(strict_types=1);

namespace Tests\Support;

use AssertionError;
use JsonException;
use Throwable;

final class Assert
{
    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $message = $message === '' ? '' : $message . ' ';
            throw new AssertionError($message . sprintf('expected %s, got %s', self::printValue($expected), self::printValue($actual)));
        }
    }

    public static function true(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new AssertionError($message === '' ? 'Expected true' : $message);
        }
    }

    public static function false(bool $condition, string $message = ''): void
    {
        if ($condition) {
            throw new AssertionError($message === '' ? 'Expected false' : $message);
        }
    }

    public static function contains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            $message = $message === '' ? '' : $message . ' ';
            throw new AssertionError($message . sprintf('string does not contain %s', self::printValue($needle)));
        }
    }

    public static function notContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            $message = $message === '' ? '' : $message . ' ';
            throw new AssertionError($message . sprintf('string must not contain %s', self::printValue($needle)));
        }
    }

    public static function throws(string $exceptionClass, callable $callback, string $message = ''): void
    {
        try {
            $callback();
        } catch (AssertionError $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($e instanceof $exceptionClass) {
                return;
            }
            throw new AssertionError(sprintf('Expected %s, got %s: %s', $exceptionClass, get_class($e), $e->getMessage()));
        }

        $message = $message === '' ? '' : $message . ' ';
        throw new AssertionError($message . sprintf('Expected %s was not thrown', $exceptionClass));
    }

    public static function jsonDecodes(string $raw): array
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AssertionError('Invalid JSON: ' . $e->getMessage());
        }

        self::true(is_array($data), 'Expected JSON object/array');

        return $data;
    }

    private static function printValue(mixed $value): string
    {
        return var_export($value, true);
    }
}
