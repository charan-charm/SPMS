<?php

declare(strict_types=1);

/**
 * 0022 — Widen bill_temp_documents.mime_type to accommodate XLS uploads.
 *
 * The original VARCHAR(16) column accommodated the three initial canonical
 * types (image/jpeg=10, image/png=9, application/pdf=15). Adding APSPDCL
 * Excel export support requires storing 'application/vnd.ms-excel' (24 chars).
 *
 * Widening to VARCHAR(64) is safe: the column carries only controlled values
 * from BillUpload::TYPE_* constants; no user-supplied string can reach it.
 *
 * Re-run-safe: the modification is idempotent — MariaDB/MySQL applies a
 * MODIFY COLUMN VARCHAR(64) over an already-64-wide column without error.
 */

return [
    'id' => '0022_widen_bill_temp_documents_mime_type',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            'ALTER TABLE bill_temp_documents
             MODIFY COLUMN mime_type VARCHAR(64) NOT NULL'
        );
    },
];
