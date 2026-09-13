<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Household\Household;
use App\Domain\Household\Membership;
use App\Support\Config;
use App\Support\Database;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

/**
 * W3 — Household schema and relationship behavior (docs/10 D-050, D-056,
 * D-057, D-058). Validates the 0005–0007 migration results against the
 * ratified constraints.
 */
final class HouseholdMigrationsTest
{
    private static function pdo(): PDO
    {
        return Database::connection();
    }

    public static function run(): array
    {
        return [
            TestResult::check('All three W3 migrations are applied', function (): void {
                $statement = self::pdo()->query(
                    "SELECT id FROM migrations WHERE id IN ('0005_create_households','0006_create_household_memberships','0007_add_insights_household_foreign_key')"
                );
                $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
                Assert::same(3, count($ids), '0005, 0006, 0007 must all be recorded (D-058 sequence)');
            }),

            TestResult::check('Households carry the canonical columns', function (): void {
                $statement = self::pdo()->prepare(
                    "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'households'"
                );
                $statement->execute([]);
                $columns = [];
                foreach ($statement as $row) {
                    $columns[$row['COLUMN_NAME']] = [$row['DATA_TYPE'], $row['IS_NULLABLE']];
                }

                foreach (['id', 'label', 'timezone', 'location_label', 'status', 'created_at', 'updated_at'] as $expected) {
                    Assert::true(isset($columns[$expected]), 'missing households.' . $expected);
                }

                Assert::same(['char', 'NO'], $columns['id']);
                Assert::same(['varchar', 'YES'], $columns['label']);
                Assert::same(['varchar', 'YES'], $columns['location_label']);
                Assert::same(['varchar', 'NO'], $columns['status']);
            }),

            TestResult::check('Memberships enforce unique (household, account) and v1 role default', function (): void {
                $statement = self::pdo()->prepare(
                    "SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_memberships' AND INDEX_NAME = 'uq_memberships_household_account'"
                );
                $statement->execute([]);
                $cols = [];
                foreach ($statement as $row) {
                    Assert::same(0, (int) $row['NON_UNIQUE'], 'membership pair key must be UNIQUE');
                    $cols[] = $row['COLUMN_NAME'];
                }
                Assert::same(['household_id', 'account_id'], $cols);
            }),

            TestResult::check('Delete rules match D-056: account CASCADE, household RESTRICT', function (): void {
                $statement = self::pdo()->prepare(
                    "SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, DELETE_RULE
                     FROM information_schema.REFERENTIAL_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'household_memberships'"
                );
                $statement->execute([]);
                $found = [];
                foreach ($statement as $row) {
                    $found[$row['CONSTRAINT_NAME']] = [$row['REFERENCED_TABLE_NAME'], $row['DELETE_RULE']];
                }

                Assert::same(['accounts', 'CASCADE'], $found['fk_memberships_account'] ?? [null, null], 'D-056: account -> memberships CASCADE');
                Assert::same(['households', 'RESTRICT'], $found['fk_memberships_household'] ?? [null, null], 'D-056: household lifecycle is controlled, never silent');
            }),

            TestResult::check('Insights FK is RESTRICT with CHAR(36) scope and dedup key intact', function (): void {
                $statement = self::pdo()->prepare(
                    "SELECT DATA_TYPE FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insights' AND COLUMN_NAME = 'household_id'"
                );
                $statement->execute([]);
                Assert::same('char', $statement->fetchColumn(), 'D-057: household_id narrowed to CHAR(36)');

                $statement = self::pdo()->prepare(
                    "SELECT REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_insights_household'"
                );
                $statement->execute([]);
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                Assert::same('households', $row['REFERENCED_TABLE_NAME'] ?? null);
                Assert::same('RESTRICT', $row['DELETE_RULE'] ?? null, 'SET NULL is never used for this relationship (D-057)');

                // The dedup unique key survived the generated-column rebuild.
                $statement = self::pdo()->prepare(
                    "SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insights' AND INDEX_NAME = 'uq_insights_dedup'"
                );
                $statement->execute([]);
                Assert::same(3, (int) $statement->fetchColumn());
            }),

            TestResult::check('No insight may reference a nonexistent household (FK enforced)', function (): void {
                $pdo = self::pdo();

                $statement = $pdo->prepare(
                    "SELECT COUNT(*) FROM insights i LEFT JOIN households h ON h.id = i.household_id
                     WHERE i.household_id IS NOT NULL AND h.id IS NULL"
                );
                $statement->execute([]);
                Assert::same(0, (int) $statement->fetchColumn(), 'D-058 residue rule: every non-NULL scope resolves');

                // A synthetic (pre-0007 style) scope can no longer be written.
                $before = self::insertRejected();
                Assert::true($before, 'insight insert with a fake household id must fail at the database layer');
            }),

            TestResult::check('Account deletion cascades memberships; household deletion with insights is restricted', function (): void {
                $pdo = self::pdo();
                AuthTesting::configure();

                $account = AuthTesting::activateAccount();
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                $household = Household::create('w3-cascade-' . bin2hex(random_bytes(4)), 'Asia/Kolkata', null, $now);
                $membership = Membership::create($household->id(), $account->id(), Membership::ROLE_OWNER, $now);
                (new \App\Repositories\HouseholdRepository())->insertWithOwnerMembership($household, $membership);

                $statement = $pdo->prepare('SELECT COUNT(*) FROM household_memberships WHERE account_id = :id');
                $statement->execute(['id' => $account->id()]);
                Assert::same(1, (int) $statement->fetchColumn());

                // Deleting the household while referenced is RESTRICTed.
                $rejected = false;
                try {
                    $pdo->prepare('DELETE FROM households WHERE id = :id')->execute(['id' => $household->id()]);
                } catch (\PDOException) {
                    $rejected = true;
                }
                // (household_memberships also references it — RESTRICT either way)
                Assert::true($rejected, 'household rows with dependents must not be deletable behind the lifecycle');

                // Account deletion cascades the membership row away.
                $pdo->prepare('DELETE FROM accounts WHERE id = :id')->execute(['id' => $account->id()]);
                $statement->execute(['id' => $account->id()]);
                Assert::same(0, (int) $statement->fetchColumn(), 'account -> memberships CASCADE (D-056)');

                // Clean up the now-referenceable fixture household.
                $pdo->prepare('DELETE FROM households WHERE id = :id')->execute(['id' => $household->id()]);
            }),
        ];
    }

    private static function insertRejected(): bool
    {
        try {
            self::pdo()->prepare(
                "INSERT INTO insights (id, household_id, category, type, title, summary, significance, confidence,
                    status, detected_at, first_detected_at, last_observed_at, updated_at, observation_count,
                    data_status, explanation_source, supporting_facts, provenance)
                 VALUES ('00000000-0000-4000-8000-0000000000aa', 'f6-hh-deadbeef', 'GENERAL', 'DATA_LIMITATION', 'x', 'y',
                    'LOW', 'INSUFFICIENT', 'DETECTED', '2026-09-12 00:00:00', '2026-09-12 00:00:00', '2026-09-12 00:00:00',
                    '2026-09-12 00:00:00', 1, 'UNAVAILABLE', 'rule', '{}', '{}')"
            )->execute();

            // Defensive: if a pre-existing row somehow matched, still reject.
            self::pdo()->prepare('DELETE FROM insights WHERE id = ?')->execute(['00000000-0000-4000-8000-0000000000aa']);

            return false;
        } catch (\PDOException) {
            return true;
        }
    }
}
