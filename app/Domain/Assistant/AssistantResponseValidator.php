<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

/**
 * Server-Side Validator verifying LLM responses for security, numerical integrity, and truth.
 */
final class AssistantResponseValidator
{
    private const MAX_LENGTH_CHARS = 4000;

    /**
     * Validate and sanitize the raw provider completion before delivery.
     *
     * @param string $content Raw completion text from LLM
     * @param string $contextText The authoritative context supplied to the LLM
     * @return array{
     *     is_valid: bool,
     *     sanitized_content: string,
     *     violations: list<string>
     * }
     */
    public function validate(string $content, string $contextText): array
    {
        $text = trim($content);
        $violations = [];

        // 1. Length constraint
        if (mb_strlen($text) > self::MAX_LENGTH_CHARS) {
            $text = mb_substr($text, 0, self::MAX_LENGTH_CHARS) . "\n\n*(Response truncated for brevity)*";
            $violations[] = 'RESPONSE_LENGTH_EXCEEDED';
        }

        // 2. Secret & API Key Leakage Detection
        if ($this->containsSecrets($text)) {
            return [
                'is_valid' => false,
                'sanitized_content' => 'I cannot fulfill this request as the generated response contained sensitive or restricted key patterns. Please ask about your SPMS solar, billing, or energy metrics.',
                'violations' => ['SENSITIVE_DATA_LEAKAGE_DETECTED'],
            ];
        }

        // 3. Automated Device Control Claim Detection
        if (preg_match('/\b(i have (turned|switched|shut) (off|on)|i will (turn|switch) (off|on) your|automatically (turned|controlled) your (inverter|appliance|pump))\b/i', $text)) {
            $text .= "\n\n*Note: SPMS is an advisory analytics platform and does not directly control physical appliances or inverters.*";
            $violations[] = 'AUTOMATED_CONTROL_CLAIM_FLAGGED';
        }

        // 4. Unsupported Grid Export / Feed-in Telemetry Detection
        if (preg_match('/\b(?:you|we)\s+exported\b|\b(?:exported|exporting)\s+to\s+(?:the\s+)?grid\b|\bgrid\s+feed-in\b/i', $text)) {
            if (!str_contains($contextText, 'CONFIRMED_GRID_EXPORT')) {
                $text .= "\n\n*(Authoritative SPMS Note: SPMS measures on-site generation and estimated consumption; it does not interface with bidirectional grid export telemetry.)*";
                $violations[] = 'UNSUPPORTED_GRID_CLAIM_FLAGGED';
            }
        }

        // 5. Actual vs Predicted Generation Confusion Guard
        if (preg_match('/\b(?:(?:you|we|your\s+system|the\s+system)\s+(?:actually\s+)?generated|actual\s+(?:solar\s+)?generation\s+was)\s+([0-9.]+\s*kWh)\b/i', $text, $genMatches)) {
            $claimedKwh = trim($genMatches[1] ?? '');
            if ($claimedKwh !== '' && str_contains($contextText, $claimedKwh)) {
                $text .= sprintf(
                    "\n\n*(Authoritative SPMS Note: %s is a forecast projection for the upcoming cycle, not an observed measurement.)*",
                    $claimedKwh
                );
                $violations[] = 'PREDICTED_ACTUAL_CONFUSION_FLAGGED';
            }
        }

        // 6. Unsupported Guaranteed Cost / Savings Claims
        if (preg_match('/\b(?:guaranteed|definite|contractual)\s+(?:savings|reduction|rebate)\b|\bwill\s+(?:definitely|certainly)\s+save\b/i', $text)) {
            $text .= "\n\n*(Authoritative SPMS Note: Energy savings estimates and cost projections are advisory indicators based on AP tariff baselines, not guaranteed contractual amounts.)*";
            $violations[] = 'UNSUPPORTED_SAVINGS_CLAIM_FLAGGED';
        }

        // 7. Numerical Reality / Contradiction Guard
        $numericalCorrection = $this->checkNumericalContradictions($text, $contextText);
        if ($numericalCorrection !== null) {
            $text .= "\n\n" . $numericalCorrection;
            $violations[] = 'NUMERICAL_DISCREPANCY_FLAGGED';
        }

        return [
            'is_valid' => true,
            'sanitized_content' => $text,
            'violations' => $violations,
        ];
    }

    /**
     * Check if text contains API key patterns, private tokens, or hex secrets.
     */
    public function containsSecrets(string $text): bool
    {
        // OpenAI / OpenRouter key pattern: sk-or-..., sk-...
        if (preg_match('/sk-[a-zA-Z0-9_\-]{20,}/', $text)) {
            return true;
        }

        // Groq key pattern: gsk_...
        if (preg_match('/gsk_[a-zA-Z0-9_\-]{20,}/', $text)) {
            return true;
        }

        // Google / Gemini key pattern (AIza...)
        if (preg_match('/AIza[a-zA-Z0-9_\-]{16,}/', $text)) {
            return true;
        }

        // JWT token patterns (e.g. GoodWe bearer token or JWT)
        if (preg_match('/eyJ[a-zA-Z0-9_\-]{10,}\.[a-zA-Z0-9_\-]{10,}/', $text) || preg_match('/eyJ[a-zA-Z0-9_\-]{20,}/', $text)) {
            return true;
        }

        // 64-character raw hex keys (e.g. SPMS_INTEGRATION_KEY)
        if (preg_match('/\b[a-f0-9]{64}\b/i', $text)) {
            return true;
        }

        // Database connection strings and environment keys
        if (preg_match('/(DB_PASSWORD|SPMS_DB_|mysql:\/\/|\.env\b)/i', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Check if the response makes an authoritative numerical claim that directly contradicts the context.
     */
    private function checkNumericalContradictions(string $response, string $contextText): ?string
    {
        // Look for balance claims like "Projected Balance: X kWh" or "BALANCE: +X kWh"
        if (preg_match('/(?:Projected Balance|BALANCE):\s*([+\-]?[0-9.]+)\s*kWh/i', $contextText, $ctxMatches)) {
            $ctxBalance = abs((float) $ctxMatches[1]);

            // If response mentions a surplus or deficit amount with kWh
            if (preg_match('/\b(?:surplus|balance|deficit)\b[^.\n]*?\b([0-9]+(?:\.[0-9]+)?)\s*kWh\b/i', $response, $respMatches)) {
                $respBalance = abs((float) $respMatches[1]);

                // If relative difference is significant (> 50% discrepancy and absolute diff > 2 kWh), provide clarification
                $diff = abs($respBalance - $ctxBalance);
                if ($diff > 2.0 && ($ctxBalance > 0 && ($diff / $ctxBalance) > 0.3)) {
                    return sprintf(
                        '*(Authoritative SPMS Note: Your official backend energy balance projection is %.3f kWh.)*',
                        $ctxBalance
                    );
                }
            }
        }

        return null;
    }
}
