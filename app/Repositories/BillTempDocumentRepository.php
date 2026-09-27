<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillTempDocument;
use App\Support\Database;
use PDO;
use Throwable;

/**
 * B2 — Temporary upload reference persistence (docs/10 D-068 §9).
 *
 * Like every bill repository, lookups are household-scoped: the bill
 * join means a temporary document row for someone else's bill simply
 * does not resolve. The table itself has no household column on purpose
 * (ownership lives exactly once, on bills — D-067).
 *
 * Deliberately not final so persistence-failure tests can subclass
 * persistUpload() (the ratified InsightRepository precedent): no
 * production code extends it.
 */
class BillTempDocumentRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function insert(BillTempDocument $doc): void
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO bill_temp_documents
                (id, bill_id, storage_key, mime_type, size_bytes, state, created_at, expires_at, deleted_at, updated_at)
             VALUES
                (:id, :bill_id, :storage_key, :mime_type, :size_bytes, :state, :created_at, :expires_at, :deleted_at, :updated_at)'
        );

        $statement->execute($doc->toStorageRow());
    }

    /**
     * The bill's current (available or tombstoned) temporary reference,
     * reachable only through the owning household.
     */
    public function findForBill(string $billId, string $householdId): ?BillTempDocument
    {
        $statement = $this->connection()->prepare(
            'SELECT t.* FROM bill_temp_documents t
             JOIN bills b ON b.id = t.bill_id
             WHERE t.bill_id = :bill_id AND b.household_id = :household_id'
        );
        $statement->execute(['bill_id' => $billId, 'household_id' => $householdId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : BillTempDocument::fromStorageRow($row);
    }

    /**
     * @return list<BillTempDocument>
     */
    public function findExpired(string $now, int $limit): array
    {
        $statement = $this->connection()->prepare(
            "SELECT * FROM bill_temp_documents
             WHERE state = 'AVAILABLE' AND expires_at <= :now
             ORDER BY expires_at ASC
             LIMIT " . max(1, min(1000, $limit))
        );
        $statement->execute(['now' => $now]);

        return array_map(
            static fn (array $row): BillTempDocument => BillTempDocument::fromStorageRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * One-way AVAILABLE -> DELETED. Returns false (honestly) when the
     * row was already gone or already deleted — callers treat that as
     * success for idempotency, never as an error.
     */
    public function markDeleted(string $id, string $now): bool
    {
        $statement = $this->connection()->prepare(
            "UPDATE bill_temp_documents
             SET state = 'DELETED', deleted_at = :now, updated_at = :now2
             WHERE id = :id AND state = 'AVAILABLE'"
        );
        $statement->execute(['id' => $id, 'now' => $now, 'now2' => $now]);

        return $statement->rowCount() === 1;
    }

    /**
     * The intake transaction: bill row created UPLOADED, moved to
     * PROCESSING, and its temporary reference attached — or none of it
     * (§12: no half-persisted uploads). Both bill writes go through the
     * B1 BillRepository on the same shared PDO connection, so they join
     * this transaction exactly as written there (no SQL duplication).
     */
    public function persistUpload(Bill $uploaded, Bill $processing, BillTempDocument $doc): void
    {
        $pdo = $this->connection();
        $bills = new BillRepository($pdo);

        $pdo->beginTransaction();
        try {
            $bills->insert($uploaded);

            if (!$bills->save($processing)) {
                throw new \RuntimeException('bill state transition affected no row');
            }

            $this->insert($doc);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connection();
    }
}
