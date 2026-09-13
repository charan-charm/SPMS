<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Household\Household;
use App\Domain\Household\Membership;
use App\Support\Database;
use PDO;
use Throwable;

/**
 * W3 — Household persistence (docs/10 D-050, D-056).
 *
 * Authentication-agnostic like every repository: it stores and retrieves
 * households by validated server-side identifiers and performs no
 * authorization decisions. The household + OWNER membership pair is
 * written atomically — a household without its authorizing membership
 * must never be observable.
 */
final class HouseholdRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function findById(string $id): ?Household
    {
        $statement = $this->connection()->prepare('SELECT * FROM households WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Household::fromStorageRow($row);
    }

    public function insert(Household $household): void
    {
        $row = $household->toStorageRow();

        $statement = $this->connection()->prepare(
            'INSERT INTO households (id, label, timezone, location_label, status, created_at, updated_at)
             VALUES (:id, :label, :timezone, :location_label, :status, :created_at, :updated_at)'
        );

        $statement->execute($row);
    }

    /**
     * Atomic household + owner-membership creation (v1 onboarding
     * primitive). Rolls back completely if either write fails.
     */
    public function insertWithOwnerMembership(Household $household, Membership $membership): void
    {
        $pdo = $this->connection();
        $pdo->beginTransaction();

        try {
            $this->insert($household);

            $row = $membership->toStorageRow();

            $statement = $pdo->prepare(
                'INSERT INTO household_memberships (id, household_id, account_id, role, created_at, updated_at)
                 VALUES (:id, :household_id, :account_id, :role, :created_at, :updated_at)'
            );

            $statement->execute($row);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public function updateStatus(Household $household): void
    {
        $statement = $this->connection()->prepare(
            'UPDATE households SET status = :status, updated_at = :updated_at WHERE id = :id'
        );

        $statement->execute([
            'status' => $household->status(),
            'updated_at' => $household->toStorageRow()['updated_at'],
            'id' => $household->id(),
        ]);
    }

    private function connection(): PDO
    {
        return $this->pdo ??= Database::connection();
    }
}
