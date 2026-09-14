<?php

declare(strict_types=1);

namespace App\Domain\Bill;

use App\Repositories\BillTempDocumentRepository;
use App\Services\Files\BillTemporaryStorageInterface;
use App\Services\Files\LocalPrivateBillStorage;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * B2 — Expired temporary document cleanup (docs/10 D-068 §21).
 *
 * Internal operation only — there is no route and no user input: the
 * service acts exclusively on database records whose expiry has passed,
 * so it can never delete another household's live document "through
 * arbitrary client input" (no input exists).
 *
 * Guarantees:
 * - idempotent: AVAILABLE rows only are scanned; marking is AVAILABLE ->
 *   DELETED once; a second pass finds nothing to do;
 * - file-missing-tolerant: storage delete() treats "already gone" as
 *   success, and a failed delete leaves the row AVAILABLE for a later
 *   pass instead of lying about cleanup;
 * - never throws because of absent state: each record is handled
 *   independently, so one stuck file cannot abort the batch.
 *
 * No scheduler is introduced (the project has none); operations can run
 * purgeExpired() whenever future infrastructure provides a timer.
 */
final class BillTempCleanupService
{
    public function __construct(
        private readonly BillTempDocumentRepository $tempDocs = new BillTempDocumentRepository(),
        private readonly BillTemporaryStorageInterface $storage = new LocalPrivateBillStorage()
    ) {
    }

    /**
     * @return array{scanned: int, cleaned: int, retained: int}
     */
    public function purgeExpired(?DateTimeImmutable $now = null, int $batchLimit = 100): array
    {
        $moment = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $scanned = 0;
        $cleaned = 0;
        $retained = 0;

        foreach ($this->tempDocs->findExpired($moment, $batchLimit) as $doc) {
            $scanned++;

            try {
                $gone = $this->storage->delete($doc->storageKey());
            } catch (Throwable) {
                $gone = false;
            }

            if (!$gone) {
                $retained++; // row stays AVAILABLE; next pass retries

                continue;
            }

            $this->tempDocs->markDeleted($doc->id(), $moment);
            $cleaned++;
        }

        return ['scanned' => $scanned, 'cleaned' => $cleaned, 'retained' => $retained];
    }
}
