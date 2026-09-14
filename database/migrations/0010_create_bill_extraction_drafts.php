<?php

declare(strict_types=1);

/**
 * B1 — Immutable AI extraction draft (docs/10 D-067).
 *
 * One row per bill capturing the NORMALIZED values the AI provider
 * originally extracted, plus document-level provenance. This table is the
 * "AI draft" half of the draft-vs-authoritative split (§7): a user
 * correction updates the bills row and its field provenance but NEVER
 * rewrites this row, so the original extraction stays reconstructable.
 *
 * Data-minimization invariant: only the same normalized SPMS-relevant
 * fields as bills are stored — NO raw OCR text, NO raw provider response,
 * NO document annotation dump, NO PII (customer/account/bill/meter/phone/
 * DTR/QR). The raw Mistral/Bedrock payloads are never persisted anywhere.
 *
 * document_confidence (overall extraction confidence) lives here and
 * nowhere else at field level (§8/§9): per-field confidence belongs to
 * bill_field_provenance. The two are structurally separate so document
 * confidence can never be silently copied into field confidence (§14).
 *
 * extraction_provider/extraction_model record WHO produced the draft —
 * provenance metadata, not a credential. NULL/empty for any value column
 * keeps its "not extracted / unknown" meaning; zero is only stored when
 * the source stated zero.
 *
 * bill_id is the aggregate ownership link: CASCADE on delete (a draft is
 * purely part of its bill aggregate; leaving it behind an orphan would be
 * meaningless). Household ownership is enforced one level up on bills;
 * nothing here is addressable without going through the owning bill.
 */

return [
    'id' => '0010_create_bill_extraction_drafts',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE bill_extraction_drafts (
                id CHAR(36) NOT NULL,
                bill_id CHAR(36) NOT NULL,
                extraction_provider VARCHAR(64) NOT NULL,
                extraction_model VARCHAR(120) NULL,
                extracted_at DATETIME NOT NULL,
                document_assessment VARCHAR(32) NOT NULL,
                document_assessment_reason VARCHAR(255) NULL,
                document_confidence DECIMAL(5,4) NULL,
                billing_period_start DATE NULL,
                billing_period_end DATE NULL,
                consumption_kwh DECIMAL(14,3) NULL,
                previous_meter_reading DECIMAL(14,3) NULL,
                present_meter_reading DECIMAL(14,3) NULL,
                previous_solar_reading DECIMAL(14,3) NULL,
                present_solar_reading DECIMAL(14,3) NULL,
                solar_units_kwh DECIMAL(14,3) NULL,
                net_units_kwh DECIMAL(14,3) NULL,
                bill_amount DECIMAL(12,2) NULL,
                arrears DECIMAL(12,2) NULL,
                total_due DECIMAL(12,2) NULL,
                energy_charges DECIMAL(12,2) NULL,
                fixed_charges DECIMAL(12,2) NULL,
                customer_service_charges DECIMAL(12,2) NULL,
                other_charges DECIMAL(12,2) NULL,
                taxes_adjustments DECIMAL(12,2) NULL,
                utility_provider VARCHAR(120) NULL,
                tariff_category VARCHAR(60) NULL,
                meter_type VARCHAR(60) NULL,
                contracted_load DECIMAL(8,2) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bill_drafts_bill (bill_id),
                CONSTRAINT fk_bill_drafts_bill FOREIGN KEY (bill_id)
                    REFERENCES bills (id) ON DELETE CASCADE ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    },
];
