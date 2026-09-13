<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Household\Household;
use App\Domain\Household\Membership;
use App\Support\Database;
use PDO;

/**
 * W3 — Membership lookups (docs/10 D-050).
 *
 * Read/store only: which authorizations exist for a given account or
 * household. The service layer interprets them; controllers never query
 * this layer directly for authorization decisions.
 */
final class MembershipRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * The account's active membership for v1: exactly one ACTIVE
     * household, resolved deterministically (oldest membership wins).
     * More than one can only exist through future multi-household work
     * and must never resolve nondeterministically.
     *
     * @return array{household: Household, membership: Membership}|null
     */
    public function findActiveByAccount(string $accountId): ?array
    {
        $statement = $this->connection()->prepare(
            "SELECT h.*, m.id AS m_id, m.household_id AS m_household_id, m.account_id AS m_account_id,
                    m.role AS m_role, m.created_at AS m_created_at, m.updated_at AS m_updated_at
             FROM household_memberships m
             JOIN households h ON h.id = m.household_id
             WHERE m.account_id = :account_id AND h.status = 'ACTIVE'
             ORDER BY m.created_at ASC, m.id ASC
             LIMIT 1"
        );

        $statement->execute(['account_id' => $accountId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'household' => Household::fromStorageRow($row),
            'membership' => Membership::fromStorageRow([
                'id' => $row['m_id'],
                'household_id' => $row['m_household_id'],
                'account_id' => $row['m_account_id'],
                'role' => $row['m_role'],
                'created_at' => $row['m_created_at'],
                'updated_at' => $row['m_updated_at'],
            ]),
        ];
    }

    public function countForAccount(string $accountId): int
    {
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*) FROM household_memberships WHERE account_id = :account_id'
        );

        $statement->execute(['account_id' => $accountId]);

        return (int) $statement->fetchColumn();
    }

    public function exists(string $householdId, string $accountId): bool
    {
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*) FROM household_memberships WHERE household_id = :household_id AND account_id = :account_id'
        );

        $statement->execute(['household_id' => $householdId, 'account_id' => $accountId]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function insert(Membership $membership): void
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO household_memberships (id, household_id, account_id, role, created_at, updated_at)
             VALUES (:id, :household_id, :account_id, :role, :created_at, :updated_at)'
        );

        $statement->execute($membership->toStorageRow());
    }

    private function connection(): PDO
    {
        return $this->pdo ??= Database::connection();
    }
}
