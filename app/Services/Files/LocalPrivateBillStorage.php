<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Support\Config;

/**
 * B2 — Private local filesystem storage for temporary bill documents
 * (docs/10 D-068 §6/§7).
 *
 * Physical layout: <root>/<opaque key> where root defaults to
 * storage/private/bills/tmp — OUTSIDE the public/ web root, so uploaded
 * documents are never web-servable and never under version control
 * (storage/private/ is gitignored). BILL_TMP_DIR may relocate the root
 * (absolute, or relative to the project base path) — it is server
 * configuration, never request data.
 *
 * Every key crosses a shape gate before touching the filesystem: only a
 * UUID stem with a canonical safe extension is accepted ("[0-9a-f-]{36}
 * .(jpg|png|pdf)"). That single regex structurally excludes path
 * separators, traversal, NUL bytes, control characters and executable
 * extensions — a key can never name ../../anything or shell.php, no
 * matter what any caller believed it contained. The client's original
 * filename never participates in naming at all.
 *
 * Windows/XAMPP note: POSIX permission bits are mostly not enforced
 * here, so security does not rest on them — it rests on (a) the location
 * being outside the document root (content can never execute as web
 * code), (b) the extension gate (no .php/.phtml/.exe/... is ever
 * written), and (c) the application NEVER including/requiring files from
 * this directory. @chmod 0600 is applied best-effort for *nix parity.
 */
final class LocalPrivateBillStorage implements BillTemporaryStorageInterface
{
    private const KEY_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(?:jpg|png|pdf|xls)$/';

    private readonly string $root;

    public function __construct(?string $baseDir = null)
    {
        $this->root = rtrim($this->resolveRoot($baseDir), '\\/');
    }

    /**
     * The private storage root for a configuration value, resolved against
     * the project base path. Exposed (pure) so the "never under public/"
     * policy is directly testable without touching Config or disk.
     *
     * @param string|null $configuredValue BILL_TMP_DIR value (null/empty => default)
     */
    public static function defaultRoot(?string $configuredValue, ?string $projectRoot = null): string
    {
        $projectRoot ??= dirname(__DIR__, 3);

        $configured = $configuredValue !== null ? trim($configuredValue) : '';

        if ($configured === '') {
            $configured = 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR
                . 'bills' . DIRECTORY_SEPARATOR . 'tmp';
        }

        // Relative values resolve against the project base path.
        if (!preg_match('/^([A-Za-z]:[\\\\\/]|[\\\\\/])/', $configured)) {
            $configured = $projectRoot . DIRECTORY_SEPARATOR . $configured;
        }

        return rtrim($configured, '\\/');
    }

    public function rootDir(): string
    {
        return $this->root;
    }

    public function store(string $storageKey, string $contents): void
    {
        $this->assertSafeKey($storageKey);
        $this->ensureRoot();

        $path = $this->path($storageKey);

        if (file_exists($path)) {
            throw new BillStorageException('A temporary document already exists under this key.');
        }

        // Write once, then hard-verify what landed: bytes or bust.
        if (@file_put_contents($path, $contents) === false) {
            throw new BillStorageException('The temporary document could not be stored.');
        }

        @chmod($path, 0600);

        if (!is_file($path) || is_dir($path)) {
            throw new BillStorageException('The stored temporary document is not verifiable.');
        }
    }

    public function exists(string $storageKey): bool
    {
        $this->assertSafeKey($storageKey);

        return is_file($this->path($storageKey));
    }

    public function read(string $storageKey): ?string
    {
        $this->assertSafeKey($storageKey);
        $path = $this->path($storageKey);

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function localPath(string $storageKey): ?string
    {
        $this->assertSafeKey($storageKey);
        $path = $this->path($storageKey);

        return is_file($path) ? $path : null;
    }

    public function delete(string $storageKey): bool
    {
        $this->assertSafeKey($storageKey);
        $path = $this->path($storageKey);

        if (!file_exists($path)) {
            return true; // idempotent by contract
        }

        if (@unlink($path) === false) {
            return file_exists($path) ? false : true;
        }

        return true;
    }

    /**
     * Keys must be exactly what intake generates: an opaque UUID plus one
     * canonical safe extension. Anything else — path characters,
     * traversal, NUL, control bytes, exotic extensions — is refused
     * before any filesystem call.
     */
    public function assertSafeKey(string $storageKey): void
    {
        if (preg_match(self::KEY_PATTERN, $storageKey) !== 1) {
            throw new BillStorageException('The storage key is not an opaque safe name.');
        }
    }

    private function resolveRoot(?string $baseDir): string
    {
        return self::defaultRoot($baseDir ?? Config::get('BILL_TMP_DIR'));
    }

    private function ensureRoot(): void
    {
        if (is_dir($this->root)) {
            return;
        }

        if (!@mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new BillStorageException('The private temporary storage directory is unavailable.');
        }
    }

    private function path(string $storageKey): string
    {
        return $this->root . DIRECTORY_SEPARATOR . $storageKey;
    }
}
