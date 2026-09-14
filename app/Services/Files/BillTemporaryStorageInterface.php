<?php

declare(strict_types=1);

namespace App\Services\Files;

/**
 * B2 — The private temporary document storage seam (docs/10 D-068).
 *
 * Everything about this interface is deliberately key-based: callers
 * (services) pass opaque storage keys and NEVER filesystem paths. The
 * physical location is a property of the implementation, so the domain
 * can never learn — or leak — where bytes live. Future cloud storage
 * implements the same seam; nothing above changes.
 *
 * Contract every implementation must satisfy:
 * - store() writes bytes under an opaque key that the implementation
 *   refuses to re-shape into anything attacker-influenced;
 * - delete() is idempotent — "already gone" is success, never an error;
 * - no method ever accepts or returns paths derived from user input.
 */
interface BillTemporaryStorageInterface
{
    /**
     * Persist the validated bytes under an opaque storage key.
     *
     * @param string $storageKey opaque generated key ("<uuid>.<ext>")
     * @param string $contents   exact source bytes (untouched, unsanitized)
     *
     * @throws BillStorageException if the bytes could not be stored
     */
    public function store(string $storageKey, string $contents): void;

    public function exists(string $storageKey): bool;

    /**
     * @return string|null exact bytes, or null when nothing is stored
     */
    public function read(string $storageKey): ?string;

    /**
     * Idempotent: a missing file deletes successfully.
     */
    public function delete(string $storageKey): bool;
}
