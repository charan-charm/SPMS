<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Account\Account;
use App\Support\Database;
use PDO;

/**
 * W1 — Account persistence (docs/10 D-049).
 *
 * Authentication-agnostic: this repository performs no authorization
 * decisions — it stores and retrieves accounts by validated identifiers
 * and normalized emails only. password_hash is storage data and never
 * leaves the repository as API output.
 */
final class AccountRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function insert(Account $account): void
    {
        $row = $account->toStorageRow();

        $statement = $this->connection()->prepare(
            'INSERT INTO accounts
                (id, email, password_hash, status, role, mode, email_verified_at, created_at, updated_at)
             VALUES
                (:id, :email, :password_hash, :status, :role, :mode, :email_verified_at, :created_at, :updated_at)'
        );

        $statement->execute($row);
    }

    public function findByEmail(string $normalizedEmail): ?Account
    {
        $statement = $this->connection()->prepare('SELECT * FROM accounts WHERE email = :email');
        $statement->execute(['email' => $normalizedEmail]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Account::fromStorageRow($row);
    }

    public function findById(string $id): ?Account
    {
        $statement = $this->connection()->prepare('SELECT * FROM accounts WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Account::fromStorageRow($row);
    }

    public function updateStatus(Account $account): void
    {
        $statement = $this->connection()->prepare(
            'UPDATE accounts SET status = :status, email_verified_at = :email_verified_at, updated_at = :updated_at WHERE id = :id'
        );

        $row = $account->toStorageRow();
        $statement->execute([
            'status' => $row['status'],
            'email_verified_at' => $row['email_verified_at'],
            'updated_at' => $row['updated_at'],
            'id' => $row['id'],
        ]);
    }

    public function updatePasswordHash(Account $account): void
    {
        $statement = $this->connection()->prepare(
            'UPDATE accounts SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id'
        );

        $row = $account->toStorageRow();
        $statement->execute([
            'password_hash' => $row['password_hash'],
            'updated_at' => $row['updated_at'],
            'id' => $row['id'],
        ]);
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connection();
    }
}
