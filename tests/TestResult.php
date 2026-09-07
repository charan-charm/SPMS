<?php

declare(strict_types=1);

namespace Tests;

use AssertionError;
use Throwable;

final class TestResult
{
    private function __construct(
        public readonly string $name,
        public readonly bool $passed,
        public readonly string $detail,
        public readonly bool $skipped
    ) {
    }

    public static function pass(string $name): self
    {
        return new self($name, true, '', false);
    }

    public static function fail(string $name, string $detail = ''): self
    {
        return new self($name, false, $detail, false);
    }

    public static function skip(string $name, string $detail = ''): self
    {
        return new self($name, true, $detail, true);
    }

    public static function check(string $name, callable $assertion): self
    {
        try {
            $assertion();
            return self::pass($name);
        } catch (AssertionError $e) {
            return self::fail($name, $e->getMessage());
        } catch (Throwable $e) {
            return self::fail($name, get_class($e) . ': ' . $e->getMessage());
        }
    }
}
