<?php

declare(strict_types=1);

/**
 * SPMS F7 — Recommendation Feedback & Measurable Outcomes Schema.
 *
 * Captures household user feedback (usefulness, follow-through, subjective reasons)
 * separately from objective recommendation outcomes (predicted vs actual solar, telemetry recovery, etc.).
 */

return [
    'id' => '0030_create_recommendation_feedback_and_outcomes',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(<<<'SQL'
            CREATE TABLE recommendation_feedback (
                id CHAR(36) NOT NULL,
                recommendation_id CHAR(36) NOT NULL,
                household_id CHAR(36) NOT NULL,
                account_id CHAR(36) NOT NULL,
                usefulness VARCHAR(16) NOT NULL,
                followed_status VARCHAR(16) NULL,
                reason VARCHAR(64) NULL,
                optional_comment TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_rec_feedback_rec FOREIGN KEY (recommendation_id)
                    REFERENCES recommendations (id) ON DELETE CASCADE,
                CONSTRAINT fk_rec_feedback_hh FOREIGN KEY (household_id)
                    REFERENCES households (id) ON DELETE CASCADE,
                CONSTRAINT fk_rec_feedback_account FOREIGN KEY (account_id)
                    REFERENCES accounts (id) ON DELETE RESTRICT,
                UNIQUE KEY uq_rec_feedback_account (recommendation_id, account_id),
                KEY idx_rec_feedback_household (household_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE recommendation_outcomes (
                id CHAR(36) NOT NULL,
                recommendation_id CHAR(36) NOT NULL,
                household_id CHAR(36) NOT NULL,
                outcome_type VARCHAR(64) NOT NULL,
                target_period_start DATETIME NULL,
                target_period_end DATETIME NULL,
                predicted_value DECIMAL(10,4) NULL,
                actual_value DECIMAL(10,4) NULL,
                evaluation_status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
                success_flag TINYINT(1) NULL,
                details_json TEXT NULL,
                created_at DATETIME NOT NULL,
                evaluated_at DATETIME NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_rec_outcomes_rec FOREIGN KEY (recommendation_id)
                    REFERENCES recommendations (id) ON DELETE CASCADE,
                CONSTRAINT fk_rec_outcomes_hh FOREIGN KEY (household_id)
                    REFERENCES households (id) ON DELETE CASCADE,
                KEY idx_rec_outcomes_rec (recommendation_id),
                KEY idx_rec_outcomes_household (household_id, outcome_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    },
];
