<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillExtractionDraft;
use App\Domain\Bill\BillField;
use App\Domain\Bill\BillQualityFlag;
use App\Domain\Bill\BillValidation;
use App\Domain\Bill\ProvenanceSource;
use App\Support\Database;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * B1 — Household-scoped bill persistence (docs/10 D-067 §4/§20).
 *
 * Authorization-agnostic like every SPMS repository, but ownership is
 * structural rather than conventional: every read and every write of a
 * bill (or its children) takes the household id as a REQUIRED parameter
 * and binds it into the WHERE clause. There is deliberately no
 * findBillById(id) — an unscoped lookup is not offered, so an id alone
 * can never address another household's data. The service resolves the
 * caller's household from membership; the repository then makes a wrong
 * pair simply return nothing.
 *
 * Multi-row writes (extraction recording, correction saving) run in one
 * transaction with the bill update — a bill must never be observable in a
 * post-extraction state whose draft, validations or provenance rows are
 * half-written.
 *
 * Value discipline: strings pass through exactly as the domain produced
 * them (PDO binds them as strings; DECIMAL columns never see a PHP
 * float), so decimals are neither truncated nor coerced on the way in,
 * and MariaDB returns them as fixed-precision strings on the way out.
 */
final class BillRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function insert(Bill $bill): void
    {
        $row = $bill->toStorageRow();
        $columns = array_merge(
            ['id', 'household_id', 'status'],
            BillField::ALL,
            ['verified_at', 'created_at', 'updated_at']
        );

        $statement = $this->connection()->prepare(
            'INSERT INTO bills (' . implode(', ', $columns) . ')
             VALUES (:' . implode(', :', $columns) . ')'
        );

        $statement->execute($this->only($row, $columns));
    }

    /**
     * The only way to load a bill: id AND household, together. A foreign
     * household yields null exactly like a nonexistent id (existence is
     * never disclosed — D-064 §3 convention).
     */
    public function find(string $billId, string $householdId): ?Bill
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM bills WHERE id = :bill_id AND household_id = :household_id'
        );
        $statement->execute(['bill_id' => $billId, 'household_id' => $householdId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Bill::fromStorageRow($row);
    }

    /**
     * @return list<Bill>
     */
    public function listForHousehold(string $householdId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT * FROM bills WHERE household_id = :household_id ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['household_id' => $householdId]);

        return array_map(
            static fn (array $row): Bill => Bill::fromStorageRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Update of every mutable column, but the WHERE pair is
     * (id, household_id): a save against the wrong household can only
     * affect 0 rows — cross-household mutation is not a policy this
     * repository can be accidentally bypassed out of. household_id and
     * created_at themselves are never in the SET list (ownership fixed).
     */
    public function save(Bill $bill): bool
    {
        $row = $bill->toStorageRow();

        $assignments = array_merge(
            ['status'],
            BillField::ALL,
            ['verified_at', 'updated_at']
        );

        $setList = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, $assignments));

        $statement = $this->connection()->prepare(
            "UPDATE bills SET {$setList} WHERE id = :id AND household_id = :household_id"
        );

        $statement->execute(array_merge(
            $this->only($row, $assignments),
            ['id' => $bill->id(), 'household_id' => $bill->householdId()]
        ));

        return $statement->rowCount() === 1;
    }

    // ------------------------------------------------------------------
    // Extraction draft (immutable)
    // ------------------------------------------------------------------

    public function insertDraft(BillExtractionDraft $draft): void
    {
        $row = $draft->toStorageRow();
        $columns = array_merge(
            ['id', 'bill_id', 'extraction_provider', 'extraction_model', 'extracted_at',
                  'document_assessment', 'document_assessment_reason', 'document_confidence'],
            BillField::ALL,
            ['created_at']
        );

        $statement = $this->connection()->prepare(
            'INSERT INTO bill_extraction_drafts (' . implode(', ', $columns) . ')
             VALUES (:' . implode(', :', $columns) . ')'
        );

        $statement->execute($this->only($row, $columns));
    }

    public function findDraft(string $billId, string $householdId): ?BillExtractionDraft
    {
        $statement = $this->connection()->prepare(
            'SELECT d.* FROM bill_extraction_drafts d
             JOIN bills b ON b.id = d.bill_id
             WHERE d.bill_id = :bill_id AND b.household_id = :household_id'
        );
        $statement->execute(['bill_id' => $billId, 'household_id' => $householdId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : BillExtractionDraft::fromStorageRow($row);
    }

    // ------------------------------------------------------------------
    // Validation results
    // ------------------------------------------------------------------

    public function upsertValidation(string $billId, BillValidation $validation, string $now): void
    {
        $statement = $this->connection()->prepare(
            'INSERT INTO bill_validation_results (id, bill_id, rule, status, expected_value, extracted_value, reason_code, created_at, updated_at)
             VALUES (:id, :bill_id, :rule, :status, :expected_value, :extracted_value, :reason_code, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                 status = VALUES(status),
                 expected_value = VALUES(expected_value),
                 extracted_value = VALUES(extracted_value),
                 reason_code = VALUES(reason_code),
                 updated_at = VALUES(updated_at)'
        );

        $statement->execute($validation->toStorageRow($billId, $now));
    }

    /**
     * @return list<BillValidation>
     */
    public function findValidations(string $billId, string $householdId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT v.* FROM bill_validation_results v
             JOIN bills b ON b.id = v.bill_id
             WHERE v.bill_id = :bill_id AND b.household_id = :household_id
             ORDER BY v.rule ASC'
        );
        $statement->execute(['bill_id' => $billId, 'household_id' => $householdId]);

        return array_map(
            static fn (array $row): BillValidation => BillValidation::fromStorageRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    // ------------------------------------------------------------------
    // Field provenance
    // ------------------------------------------------------------------

    /**
     * @param array<string, array{source: string, confidence: string|null}> $entries field => provenance
     */
    public function upsertProvenance(string $billId, array $entries, string $now): void
    {
        if ($entries === []) {
            return;
        }

        $statement = $this->connection()->prepare(
            'INSERT INTO bill_field_provenance (id, bill_id, field_name, source, confidence, created_at, updated_at)
             VALUES (:id, :bill_id, :field_name, :source, :confidence, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                 source = VALUES(source),
                 confidence = VALUES(confidence),
                 updated_at = VALUES(updated_at)'
        );

        foreach ($entries as $field => $entry) {
            if (!is_string($field) || !BillField::isPersistable($field) || !ProvenanceSource::isValid($entry['source'])) {
                throw new InvalidArgumentException('invalid provenance entry for field: ' . (is_string($field) ? $field : get_debug_type($field)));
            }

            $statement->execute([
                'id' => self::uuid(),
                'bill_id' => $billId,
                'field_name' => $field,
                'source' => $entry['source'],
                'confidence' => $entry['confidence'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<string, array{source: string, confidence: string|null}>
     */
    public function findProvenance(string $billId, string $householdId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT p.field_name, p.source, p.confidence FROM bill_field_provenance p
             JOIN bills b ON b.id = p.bill_id
             WHERE p.bill_id = :bill_id AND b.household_id = :household_id'
        );
        $statement->execute(['bill_id' => $billId, 'household_id' => $householdId]);

        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['field_name']] = [
                'source' => (string) $row['source'],
                'confidence' => $row['confidence'] !== null ? (string) $row['confidence'] : null,
            ];
        }

        return $map;
    }

    // ------------------------------------------------------------------
    // Quality flags
    // ------------------------------------------------------------------

    /**
     * Idempotent statement: (code, field) already recorded for this bill
     * is not duplicated (NULL-safe compare — a document-wide flag's
     * field_name is genuinely NULL, and MySQL unique semantics alone
     * would allow infinite duplicates of it).
     */
    public function recordQualityFlag(string $billId, BillQualityFlag $flag, string $now): void
    {
        $check = $this->connection()->prepare(
            'SELECT id FROM bill_quality_flags
             WHERE bill_id = :bill_id AND code = :code AND field_name <=> :field_name'
        );
        $check->execute(['bill_id' => $billId, 'code' => $flag->code(), 'field_name' => $flag->fieldName()]);

        if ($check->fetchColumn() !== false) {
            return;
        }

        $statement = $this->connection()->prepare(
            'INSERT INTO bill_quality_flags (id, bill_id, code, field_name, created_at, updated_at)
             VALUES (:id, :bill_id, :code, :field_name, :created_at, :updated_at)'
        );

        $statement->execute($flag->toStorageRow($billId, $now));
    }

    /**
     * @return list<BillQualityFlag>
     */
    public function findQualityFlags(string $billId, string $householdId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT q.* FROM bill_quality_flags q
             JOIN bills b ON b.id = q.bill_id
             WHERE q.bill_id = :bill_id AND b.household_id = :household_id
             ORDER BY q.code ASC, q.field_name ASC'
        );
        $statement->execute(['bill_id' => $billId, 'household_id' => $householdId]);

        return array_map(
            static fn (array $row): BillQualityFlag => BillQualityFlag::fromStorageRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    // ------------------------------------------------------------------
    // Atomic composition (the transaction lives here, not in callers)
    // ------------------------------------------------------------------

    /**
     * Persist one extraction outcome: updated bill + its immutable draft
     * + validation results + quality flags + AI provenance, all or
     * nothing.
     *
     * @param list<BillValidation>  $validations
     * @param list<BillQualityFlag> $flags
     * @param array<string, array{source: string, confidence: string|null}> $provenance
     */
    public function recordExtraction(
        Bill $bill,
        BillExtractionDraft $draft,
        array $validations,
        array $flags,
        array $provenance
    ): void {
        $pdo = $this->connection();
        $now = $bill->updatedAt();

        $pdo->beginTransaction();
        try {
            if (!$this->save($bill)) {
                throw new PDOException('bill row vanished under an authorized household-scoped save');
            }

            $this->insertDraft($draft);

            foreach ($validations as $validation) {
                $this->upsertValidation($bill->id(), $validation, $now);
            }

            foreach ($flags as $flag) {
                $this->recordQualityFlag($bill->id(), $flag, $now);
            }

            $this->upsertProvenance($bill->id(), $provenance, $now);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Persist user corrections + their provenance atomically. The
     * extraction draft is not merely untouched — it is not addressed by
     * this method at all (§7/§12).
     *
     * @param array<string, array{source: string, confidence: string|null}> $provenance
     */
    public function saveCorrections(Bill $bill, array $provenance): void
    {
        $pdo = $this->connection();

        $pdo->beginTransaction();
        try {
            if (!$this->save($bill)) {
                throw new PDOException('bill row vanished under an authorized household-scoped save');
            }

            $this->upsertProvenance($bill->id(), $provenance, $bill->updatedAt());

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Restrict a storage row to exactly the bound columns (no extra keys
     * reach a prepared statement, so an unexpected value can never slip a
     * column into or out of a write).
     *
     * @param array<string, mixed> $row
     * @param list<string>         $columns
     * @return array<string, mixed>
     */
    private function only(array $row, array $columns): array
    {
        $missing = array_diff($columns, array_keys($row));
        if ($missing !== []) {
            throw new InvalidArgumentException('storage row is missing columns: ' . implode(', ', $missing));
        }

        return array_intersect_key($row, array_flip($columns));
    }

    private function connection(): PDO
    {
        return $this->pdo ??= Database::connection();
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
