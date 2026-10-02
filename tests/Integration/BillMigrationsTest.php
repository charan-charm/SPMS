<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Bill\BillField;
use App\Support\Database;
use PDO;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * B1 — Bill schema shape checks (docs/10 D-067).
 *
 * Asserts the migrated structure itself: exact column inventories (the
 * strongest data-minimization guarantee — a column nobody approved simply
 * may not exist), ownership delete rules (household RESTRICT, aggregate
 * children CASCADE), fixed-precision DECIMAL money/energy typing with no
 * FLOAT/DOUBLE anywhere, large-object/document-typed columns nowhere, and
 * the keys that back scoped listing and immutable relationships.
 */
final class BillMigrationsTest
{
    private const TABLES = [
        'bills',
        'bill_extraction_drafts',
        'bill_field_provenance',
        'bill_validation_results',
        'bill_quality_flags',
        'bill_temp_documents', // B2 (docs/10 D-068) joins the aggregate scan
    ];

    private static function pdo(): PDO
    {
        return Database::connection();
    }

    /**
     * column => "COLUMN_TYPE,IS_NULLABLE"
     *
     * @return array<string, string>
     */
    private static function columns(string $table): array
    {
        $statement = self::pdo()->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $statement->execute(['t' => $table]);

        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['COLUMN_NAME']] = $row['COLUMN_TYPE'] . ',' . $row['IS_NULLABLE'];
        }

        return $map;
    }

    public static function run(): array
    {
        return [
            TestResult::check('B1 migrations are recorded and every bills-aggregate table exists', function (): void {
                $ids = self::pdo()->query('SELECT id FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

                foreach ([
                    '0009_create_bills',
                    '0010_create_bill_extraction_drafts',
                    '0011_create_bill_field_provenance',
                    '0012_create_bill_validation_results',
                    '0013_create_bill_quality_flags',
                ] as $expected) {
                    Assert::true(in_array($expected, $ids, true), 'migration not recorded: ' . $expected);
                }

                foreach (self::TABLES as $table) {
                    $statement = self::pdo()->prepare(
                        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
                    );
                    $statement->execute(['t' => $table]);
                    Assert::same(1, (int) $statement->fetchColumn(), 'missing table: ' . $table);
                }
            }),

            TestResult::check('bills column inventory is EXACTLY the ratified SPMS field set', function (): void {
                $expected = array_merge(
                    ['id', 'household_id', 'status'],
                    BillField::ALL,
                    ['verified_at', 'created_at', 'updated_at', 'dedup_active_period', 'fingerprint']
                );
                sort($expected);

                $actual = array_keys(self::columns('bills'));
                sort($actual);

                Assert::same($expected, $actual, 'no schema drift is allowed in either direction');
            }),

            TestResult::check('bill_extraction_drafts column inventory is the same values plus document provenance', function (): void {
                $expected = array_merge(
                    ['id', 'bill_id', 'extraction_provider', 'extraction_model', 'extracted_at',
                          'document_assessment', 'document_assessment_reason', 'document_confidence'],
                    BillField::ALL,
                    ['created_at']
                );
                sort($expected);

                $actual = array_keys(self::columns('bill_extraction_drafts'));
                sort($actual);

                Assert::same($expected, $actual);
            }),

            TestResult::check('child tables have exactly their normalized columns', function (): void {
                $provenance = array_keys(self::columns('bill_field_provenance'));
                sort($provenance);
                Assert::same(
                    ['bill_id', 'confidence', 'created_at', 'field_name', 'id', 'source', 'updated_at'],
                    $provenance
                );

                $validations = array_keys(self::columns('bill_validation_results'));
                sort($validations);
                Assert::same(
                    ['bill_id', 'created_at', 'expected_value', 'extracted_value', 'id', 'reason_code', 'rule', 'status', 'updated_at'],
                    $validations
                );

                $quality = array_keys(self::columns('bill_quality_flags'));
                sort($quality);
                Assert::same(
                    ['bill_id', 'code', 'created_at', 'field_name', 'id', 'updated_at'],
                    $quality
                );
            }),

            TestResult::check('ownership: bills -> household RESTRICT/RESTRICT (SET NULL never, silent loss impossible)', function (): void {
                $statement = self::pdo()->prepare(
                    "SELECT REFERENCED_TABLE_NAME, DELETE_RULE, UPDATE_RULE
                     FROM information_schema.REFERENTIAL_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_bills_household'"
                );
                $statement->execute([]);
                $row = $statement->fetch(PDO::FETCH_ASSOC);

                Assert::true(is_array($row), 'fk_bills_household must exist');
                Assert::same('households', $row['REFERENCED_TABLE_NAME']);
                Assert::same('RESTRICT', $row['DELETE_RULE']);
                Assert::same('RESTRICT', $row['UPDATE_RULE']);
            }),

            TestResult::check('aggregate children follow their bill (CASCADE) and nothing else references bills', function (): void {
                $statement = self::pdo()->prepare(
                    "SELECT TABLE_NAME, DELETE_RULE
                     FROM information_schema.REFERENTIAL_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'bills'"
                );
                $statement->execute([]);

                $found = [];
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $found[(string) $row['TABLE_NAME']] = (string) $row['DELETE_RULE'];
                }
                ksort($found);

                Assert::same(
                    [
                        'bill_extraction_drafts' => 'CASCADE',
                        'bill_field_provenance' => 'CASCADE',
                        'bill_quality_flags' => 'CASCADE',
                        'bill_temp_documents' => 'CASCADE',
                        'bill_validation_results' => 'CASCADE',
                    ],
                    $found
                );
            }),

            TestResult::check('numeric storage is fixed-precision DECIMAL at ratified scale', function (): void {
                $bills = self::columns('bills');
                $drafts = self::columns('bill_extraction_drafts');

                foreach ([1 => $bills, 2 => $drafts] as $columns) {
                    foreach (BillField::ENERGY as $field) {
                        Assert::same('decimal(14,3),YES', $columns[$field] ?? '(missing)', $field);
                    }
                    foreach (BillField::MONEY as $field) {
                        Assert::same('decimal(12,2),YES', $columns[$field] ?? '(missing)', $field);
                    }
                    Assert::same('decimal(8,2),YES', $columns['contracted_load'] ?? '(missing)');
                    foreach (BillField::DATES as $field) {
                        Assert::same('date,YES', $columns[$field] ?? '(missing)', $field);
                    }
                }

                Assert::same('decimal(5,4),YES', $drafts['document_confidence'] ?? '(missing)', 'document confidence: bounded fixed precision');
                Assert::same('decimal(5,4),YES', self::columns('bill_field_provenance')['confidence'] ?? '(missing)');
                Assert::same('decimal(14,3),YES', self::columns('bill_validation_results')['expected_value'] ?? '(missing)');
                Assert::same('decimal(14,3),YES', self::columns('bill_validation_results')['extracted_value'] ?? '(missing)');
            }),

            TestResult::check('no FLOAT/DOUBLE/blob/TEXT/JSON column anywhere in the bills schema', function (): void {
                $statement = self::pdo()->prepare(
                    "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME IN ('bills','bill_extraction_drafts','bill_field_provenance','bill_validation_results','bill_quality_flags','bill_temp_documents')
                       AND DATA_TYPE IN ('float','double','real','blob','tinyblob','mediumblob','longblob','binary','varbinary','text','tinytext','mediumtext','longtext','json')"
                );
                $statement->execute([]);
                Assert::same([], $statement->fetchAll(PDO::FETCH_ASSOC), 'floating money, document blobs and raw-text dumps are structurally excluded');
            }),

            TestResult::check('NULL semantics: every analytical column is nullable with no zero-defaulting', function (): void {
                $statement = self::pdo()->prepare(
                    'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
                );
                $statement->execute(['t' => 'bills']);

                $rows = [];
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $rows[(string) $row['COLUMN_NAME']] = $row;
                }

                foreach (BillField::ALL as $field) {
                    Assert::same('YES', $rows[$field]['IS_NULLABLE'], $field . ' must allow NULL');
                    // MariaDB reports an absent default as NULL or the string 'NULL'.
                    $default = $rows[$field]['COLUMN_DEFAULT'];
                    Assert::true(
                        $default === null || $default === 'NULL',
                        $field . ' must have NO default (NULL is the only unknown marker, never 0)'
                    );
                }
            }),

            TestResult::check('identity/timestamp/status columns follow the ratified conventions', function (): void {
                $bills = self::columns('bills');
                Assert::same('char(36),NO', $bills['id']);
                Assert::same('char(36),NO', $bills['household_id']);
                Assert::same('varchar(32),NO', $bills['status']);
                Assert::same('datetime,YES', $bills['verified_at']);
                Assert::same('datetime,NO', $bills['created_at']);
                Assert::same('datetime,NO', $bills['updated_at']);
            }),

            TestResult::check('indexing: scoped listing key and the ratified unique guarantees exist', function (): void {
                $statement = self::pdo()->prepare(
                    "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols, MIN(NON_UNIQUE) AS non_unique
                     FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME IN ('bills','bill_extraction_drafts','bill_field_provenance','bill_validation_results','bill_quality_flags','bill_temp_documents')
                     GROUP BY TABLE_NAME, INDEX_NAME"
                );
                $statement->execute([]);

                $keys = [];
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $keys[(string) $row['INDEX_NAME']] = [(string) $row['cols'], (int) $row['non_unique']];
                }

                Assert::same('household_id,status', $keys['idx_bills_household_status'][0], 'every bill listing is household-scoped');
                Assert::same(1, $keys['idx_bills_household_status'][1]);

                Assert::same('bill_id', $keys['uq_bill_drafts_bill'][0], 'one immutable draft per bill (B1)');
                Assert::same(0, $keys['uq_bill_drafts_bill'][1]);
                Assert::same('bill_id,field_name', $keys['uq_bill_provenance_field'][0]);
                Assert::same(0, $keys['uq_bill_provenance_field'][1]);
                Assert::same('bill_id,rule', $keys['uq_bill_validation_bill_rule'][0], 'one current result per rule per bill');
                Assert::same(0, $keys['uq_bill_validation_bill_rule'][1]);
                Assert::same('bill_id,code,field_name', $keys['uq_bill_quality_bill_code_field'][0]);
                Assert::same(0, $keys['uq_bill_quality_bill_code_field'][1]);
            }),

            TestResult::check('the schema carries no PII/raw-document column under any name (hard minimization scan)', function (): void {
                $forbiddenExact = [
                    'name', 'customer', 'customer_name', 'email', 'phone', 'mobile', 'aadhaar',
                    'aadhaar_number', 'account_number', 'usc', 'usc_number', 'bill_number',
                    'meter_serial', 'dtr_code', 'qr_payload',
                ];
                $forbiddenFragments = ['raw', 'ocr', 'annotation', 'blob', 'binary', 'base64', 'image', 'pdf', 'file', 'dump', 'url'];

                foreach (self::TABLES as $table) {
                    foreach (array_keys(self::columns($table)) as $column) {
                        $lower = strtolower($column);
                        Assert::false(in_array($lower, $forbiddenExact, true), $table . '.' . $column . ' would store identity data');
                        foreach ($forbiddenFragments as $fragment) {
                            Assert::false(str_contains($lower, $fragment), $table . '.' . $column . ' would store raw/document data');
                        }
                    }
                }
            }),
        ];
    }
}
