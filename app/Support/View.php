<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Minimal presentation-only view renderer for server-rendered pages.
 *
 * Views live in resources/views/. render() combines a layout with a
 * page-specific partial. Escaping helpers live in View::e().
 *
 * Views must contain presentation markup only — no business logic,
 * no database access, no service calls.
 */
final class View
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $layout, string $page, array $data = []): string
    {
        $viewDir = dirname(__DIR__, 2) . '/resources/views';

        $layoutFile = $viewDir . '/' . $layout . '.php';
        $pageFile = $viewDir . '/' . $page . '.php';

        if (!is_file($layoutFile)) {
            throw new InvalidArgumentException("Layout not found: {$layout}");
        }
        if (!is_file($pageFile)) {
            throw new InvalidArgumentException("View not found: {$page}");
        }

        $renderPartial = static function () use ($pageFile, $data): string {
            extract($data, EXTR_SKIP);
            ob_start();
            include $pageFile;
            return (string) ob_get_clean();
        };

        $content = $renderPartial();

        extract($data, EXTR_SKIP);
        ob_start();
        include $layoutFile;
        return (string) ob_get_clean();
    }
}
