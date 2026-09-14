<?php

declare(strict_types=1);

/**
 * B2 — Temporary bill document records (docs/10 D-068).
 *
 * The secure intake boundary needs ONE persisted thing: an internal
 * reference binding a bill to its private temporary upload file, with
 * an explicit expiry so the document cannot linger. This table holds
 * ONLY operational metadata:
 *
 * - storage_key: an opaque generated filename (UUID + canonical safe
 *   extension) inside the private non-web storage root. The physical
 *   directory is server configuration, never data — a leaked key is a
 *   meaningless filename without the (also private) root path.
 * - mime_type / size_bytes: the normalized facts B3 needs before it
 *   opens the file (the client-declared values were never trusted).
 * - state: AVAILABLE -> DELETED lifecycle marker for idempotent cleanup.
 *
 * Deliberately absent: the original filename (user-controlled, analytically
 * useless, never a path component), any content, any OCR/AI data, any PII.
 * The document itself never enters the database (§2 data-minimization).
 *
 * bill_id CASCADE: the temporary record is purely part of its bill
 * aggregate (ownership is enforced through bills.household_id, never
 * re-duplicated here). uq(bill_id): one live upload reference per bill.
 *
 * Timestamps are UTC DATETIME written by the application (docs/05 §13).
 */

return [
    'id' => '0014_create_bill_temp_documents',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE bill_temp_documents (
                id CHAR(36) NOT NULL,
                bill_id CHAR(36) NOT NULL,
                storage_key VARCHAR(191) NOT NULL,
                mime_type VARCHAR(16) NOT NULL,
                size_bytes INT UNSIGNED NOT NULL,
                state VARCHAR(16) NOT NULL DEFAULT 'AVAILABLE',
                created_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                deleted_at DATETIME NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bill_temp_storage_key (storage_key),
                UNIQUE KEY uq_bill_temp_bill (bill_id),
                KEY idx_bill_temp_state_expiry (state, expires_at),
                CONSTRAINT fk_bill_temp_bill FOREIGN KEY (bill_id)
                    REFERENCES bills (id) ON DELETE CASCADE ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
