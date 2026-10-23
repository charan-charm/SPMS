<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Database;
use PDO;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * W1 — Authentication schema verification (docs/10 D-049–D-058).
 *
 * Verifies the migrated structure in the real database: tables,
 * essential columns, unique constraints, foreign keys with their
 * ratified delete rules, and strict SQL mode on the SPMS connection.
 */
final class AuthMigrationsTest
{
    public static function run(): array
    {
        return [
            TestResult::check('All four W1 tables exist', function (): void {
                $pdo = Database::connection();

                foreach (['accounts', 'sessions', 'account_tokens', 'security_events'] as $table) {
                    $row = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetch(PDO::FETCH_NUM);
                    Assert::true(is_array($row), "table {$table} must exist");
                }
            }),

            TestResult::check('Accounts carry the canonical identity columns', function (): void {
                $columns = self::columns('accounts');

                foreach (['id', 'email', 'password_hash', 'status', 'role', 'mode', 'email_verified_at', 'created_at', 'updated_at'] as $column) {
                    Assert::true(isset($columns[$column]), "accounts.{$column} must exist");
                }

                Assert::same('char(36)', $columns['id']);
                Assert::same('varchar(255)', $columns['email']);
                Assert::same('datetime', $columns['email_verified_at']);
                Assert::true(self::indexExists('accounts', 'uq_accounts_email'), 'email must be unique');
            }),

            TestResult::check('Sessions store only token hashes and expire server-side', function (): void {
                $columns = self::columns('sessions');

                foreach (['id', 'account_id', 'session_token_hash', 'csrf_token', 'created_at', 'last_activity_at', 'expires_at', 'absolute_expires_at', 'revoked_at'] as $column) {
                    Assert::true(isset($columns[$column]), "sessions.{$column} must exist");
                }

                Assert::true(self::indexExists('sessions', 'uq_sessions_token_hash'), 'session token hash must be unique');
                Assert::true(self::indexExists('sessions', 'idx_sessions_account_revoked'), 'account lookup index required');
                Assert::true(self::indexExists('sessions', 'idx_sessions_expires_at'), 'expiry index required');
            }),

            TestResult::check('Account tokens are typed, single-use columns present, hash unique', function (): void {
                $columns = self::columns('account_tokens');

                foreach (['id', 'account_id', 'type', 'token_hash', 'expires_at', 'used_at', 'created_at'] as $column) {
                    Assert::true(isset($columns[$column]), "account_tokens.{$column} must exist");
                }

                Assert::true(self::indexExists('account_tokens', 'uq_account_tokens_token_hash'));
            }),

            TestResult::check('Sessions and account_tokens cascade on account deletion (D-056)', function (): void {
                $pdo = Database::connection();

                $rules = self::foreignKeyRules();

                Assert::same('CASCADE', $rules['sessions']['fk_sessions_account'] ?? null);
                Assert::same('CASCADE', $rules['account_tokens']['fk_account_tokens_account'] ?? null);

                // Behavioral proof: an account row with a session and a
                // token disappears together with both dependents.
                $accountId = self::insertTestAccount($pdo);
                $pdo->prepare("INSERT INTO sessions (id, account_id, session_token_hash, csrf_token, created_at, last_activity_at, expires_at, absolute_expires_at) VALUES (UUID(), ?, SHA2('w1-test-session', 256), SHA2('w1-test-csrf', 256), UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))")
                    ->execute([$accountId]);
                $pdo->prepare("INSERT INTO account_tokens (id, account_id, type, token_hash, expires_at, created_at) VALUES (UUID(), ?, 'EMAIL_VERIFICATION', SHA2('w1-test-token', 256), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), UTC_TIMESTAMP())")
                    ->execute([$accountId]);

                $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$accountId]);

                $sessionCount = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE session_token_hash = SHA2('w1-test-session', 256)")->fetchColumn();
                $tokenCount = (int) $pdo->query("SELECT COUNT(*) FROM account_tokens WHERE token_hash = SHA2('w1-test-token', 256)")->fetchColumn();

                Assert::same(0, $sessionCount, 'session must cascade with its account');
                Assert::same(0, $tokenCount, 'token must cascade with its account');
            }),

            TestResult::check('Security events survive account deletion — no destructive cascade (D-056)', function (): void {
                $pdo = Database::connection();

                $rules = self::foreignKeyRules();
                Assert::true(!isset($rules['security_events']) || $rules['security_events'] === [], 'security_events must have no foreign keys');

                $accountId = self::insertTestAccount($pdo);
                $pdo->prepare("INSERT INTO security_events (id, event_type, context_key, account_id, created_at) VALUES (UUID(), 'TEST_EVENT', 'w1-test', ?, UTC_TIMESTAMP())")
                    ->execute([$accountId]);

                $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$accountId]);

                $remaining = (int) $pdo->query("SELECT COUNT(*) FROM security_events WHERE context_key = 'w1-test'")->fetchColumn();
                Assert::same(1, $remaining, 'security history must outlive the account');

                $pdo->prepare("DELETE FROM security_events WHERE context_key = 'w1-test'")->execute();
            }),

            TestResult::check('SPMS connection enforces strict SQL mode without touching the server default (D-058)', function (): void {
                $pdo = Database::connection();

                $sessionMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
                Assert::contains('STRICT_TRANS_TABLES', $sessionMode);

                $globalMode = (string) $pdo->query('SELECT @@GLOBAL.sql_mode')->fetchColumn();
                Assert::notContains('STRICT_TRANS_TABLES', $globalMode, 'global XAMPP configuration must stay untouched');
            }),

            TestResult::check('SessionService::revoke handles complete, partial, and empty session representations without warnings', function (): void {
                $pdo = Database::connection();
                $accountId = self::insertTestAccount($pdo);
                $service = new \App\Domain\Auth\SessionService();

                // 1. Valid complete session
                $started = $service->start($accountId);
                $session = $started['session'];
                Assert::true(isset($session['id']) && isset($session['account_id']));

                $revoked = $service->revoke($session, 'UNIT_TEST_LOGOUT');
                Assert::true($revoked, 'Must report successful revocation for valid session');

                $repo = new \App\Repositories\SessionRepository();
                $persisted = $repo->findById((string) $session['id']);
                Assert::true($persisted !== null);
                Assert::true($persisted['revoked_at'] !== null, 'Session must be marked revoked');

                // 2. Partial session: only id present (missing account_id)
                $started2 = $service->start($accountId);
                $partialSessionWithIdOnly = ['id' => $started2['session']['id']];

                $revokedPartial = $service->revoke($partialSessionWithIdOnly, 'PARTIAL_TEST_REVOKE');
                Assert::true($revokedPartial, 'Must resolve account_id and revoke session');

                $persisted2 = $repo->findById((string) $started2['session']['id']);
                Assert::true($persisted2 !== null);
                Assert::true($persisted2['revoked_at'] !== null);

                // 3. Incomplete session: missing id or empty array
                $emptySession = [];
                $revokedEmpty = $service->revoke($emptySession, 'EMPTY_REVOKE');
                Assert::false($revokedEmpty, 'Must return false and not report success when session has no id');

                $partialNoId = ['account_id' => $accountId];
                $revokedNoId = $service->revoke($partialNoId, 'NO_ID_REVOKE');
                Assert::false($revokedNoId, 'Must return false when id is missing');

                // 4. Nonexistent id
                $nonExistent = ['id' => '00000000-0000-0000-0000-000000000000'];
                $revokedNonExistent = $service->revoke($nonExistent, 'NONEXISTENT_REVOKE');
                Assert::false($revokedNonExistent, 'Must return false when session does not exist');

                // Clean up test account
                $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$accountId]);
            }),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function columns(string $table): array
    {
        $rows = Database::connection()->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row['Field']] = strtolower((string) $row['Type']);
        }

        return $columns;
    }

    private static function indexExists(string $table, string $indexName): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
        );
        $statement->execute(['table' => $table, 'index' => $indexName]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @return array<string, array<string, string>> table => constraint => delete rule
     */
    private static function foreignKeyRules(): array
    {
        $statement = Database::connection()->prepare(
            'SELECT table_name, constraint_name, delete_rule FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE() AND table_name IN ("sessions", "account_tokens", "security_events")'
        );
        $statement->execute();

        $rules = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rules[$row['table_name']][$row['constraint_name']] = $row['delete_rule'];
        }

        return $rules;
    }

    private static function insertTestAccount(PDO $pdo): string
    {
        $id = self::uuid();
        $pdo->prepare("INSERT INTO accounts (id, email, password_hash, status, role, mode, created_at, updated_at) VALUES (?, ?, 'w1-test-hash', 'ACTIVE', 'USER', 'REAL', UTC_TIMESTAMP(), UTC_TIMESTAMP())")
            ->execute([$id, 'w1-migration-test-' . bin2hex(random_bytes(4)) . '@example.com']);

        return $id;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
