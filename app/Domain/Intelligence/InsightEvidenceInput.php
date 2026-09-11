<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * F6 — Canonical evidence input (docs/10 D-044/D-045).
 *
 * The single validated structure every detector reads. It is built either
 * by the F4/F5 adapter (backend-controlled refresh) or by deterministic
 * tests — raw API/user data can never bypass this validation.
 *
 * Honesty rules:
 * - The input carries evidence exactly as produced; F6 never recomputes
 *   solar-minus-consumption balances or kWh-times-rate costs.
 * - Missing sections mean "not provided"; they never mean zero.
 * - A value period cannot simultaneously claim UNAVAILABLE status — that
 *   combination is rejected instead of silently reinterpreted.
 */
final class InsightEvidenceInput
{
    private const NATURES = EvidenceSemantics::NATURES;
    private const STATUSES = ['FRESH', 'STALE', 'DEGRADED'];
    private const QUALITIES = EvidenceSemantics::QUALITIES;
    private const CONFIDENCES = ['HIGH', 'MODERATE', 'LOW', 'VERY_LOW'];
    private const BALANCE_CLASSIFICATIONS = ['POTENTIAL_SURPLUS', 'BALANCED', 'POTENTIAL_DEFICIT'];
    private const BALANCE_STATES = ['CLASSIFIED', 'UNAVAILABLE'];
    private const COST_STATES = ['ESTIMATED', 'UNAVAILABLE', 'INSUFFICIENT_EVIDENCE'];
    private const VALUE_MAX = 1_000_000.0;

    /**
     * @param array<int, array<string, mixed>> $solarPeriods
     * @param array<int, array<string, mixed>> $consumptionPeriods
     * @param array<int, array<string, mixed>> $forecastPeriods
     * @param array<int, array<string, mixed>> $balanceResults
     * @param array<int, array<string, mixed>> $costResults
     * @param array<string, mixed> $historical
     * @param array<string, mixed> $qualityContext
     * @param array<string, mixed> $provenanceContext
     */
    private function __construct(
        private readonly array $solarPeriods,
        private readonly array $consumptionPeriods,
        private readonly array $forecastPeriods,
        private readonly array $balanceResults,
        private readonly array $costResults,
        private readonly array $historical,
        private readonly ?string $householdId,
        private readonly array $qualityContext,
        private readonly array $provenanceContext
    ) {
    }

    /**
     * @param array<string, mixed> $evidence
     */
    public static function fromArray(array $evidence): self
    {
        $householdId = $evidence['household_id'] ?? null;
        if ($householdId !== null && (!is_string($householdId) || $householdId === '' || mb_strlen($householdId) > 64)) {
            throw new \InvalidArgumentException('household_id must be a string of at most 64 characters.');
        }

        $qualityContext = self::validatedQualityContext($evidence['quality'] ?? null);
        $provenanceContext = self::validatedProvenanceContext($evidence['provenance'] ?? null);

        return new self(
            self::validatedPeriods($evidence['solar'] ?? null, 'solar'),
            self::validatedPeriods($evidence['consumption'] ?? null, 'consumption'),
            self::validatedPeriods($evidence['forecast'] ?? null, 'forecast'),
            self::validatedBalanceResults($evidence['balance'] ?? null),
            self::validatedCostResults($evidence['cost'] ?? null),
            self::validatedHistorical($evidence['historical'] ?? null),
            $householdId,
            $qualityContext,
            $provenanceContext
        );
    }

    public function householdId(): ?string
    {
        return $this->householdId;
    }

    /** @return array<int, array<string, mixed>> */
    public function solarPeriods(): array
    {
        return $this->solarPeriods;
    }

    /** @return array<int, array<string, mixed>> */
    public function consumptionPeriods(): array
    {
        return $this->consumptionPeriods;
    }

    /** @return array<int, array<string, mixed>> */
    public function forecastPeriods(): array
    {
        return $this->forecastPeriods;
    }

    /** @return array<int, array<string, mixed>> */
    public function balanceResults(): array
    {
        return $this->balanceResults;
    }

    /** @return array<int, array<string, mixed>> */
    public function costResults(): array
    {
        return $this->costResults;
    }

    /** @return array<string, mixed> */
    public function historical(): array
    {
        return $this->historical;
    }

    /** @return array{overall_status: ?string, overall_quality: ?string} */
    public function qualityContext(): array
    {
        return $this->qualityContext;
    }

    /** @return array{sources: array<int, string>, pipeline: array<int, string>} */
    public function provenanceContext(): array
    {
        return $this->provenanceContext;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function validatedPeriods(mixed $raw, string $field): array
    {
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw) || array_is_list($raw) === false) {
            throw new \InvalidArgumentException($field . ' must be a list of period records.');
        }

        $periods = [];
        foreach ($raw as $index => $record) {
            if (!is_array($record)) {
                throw new \InvalidArgumentException(sprintf('%s.%d must be an object.', $field, $index));
            }

            $value = $record['value_kwh'] ?? null;
            if (!is_int($value) && !is_float($value)) {
                throw new \InvalidArgumentException(sprintf('%s.%d.value_kwh must be a number.', $field, $index));
            }
            $value = (float) $value;
            if (!is_finite($value) || $value < 0.0 || $value > self::VALUE_MAX) {
                throw new \InvalidArgumentException(sprintf('%s.%d.value_kwh must be a finite number between 0 and 1,000,000.', $field, $index));
            }

            $nature = $record['data_nature'] ?? null;
            if (!is_string($nature) || !in_array($nature, self::NATURES, true)) {
                throw new \InvalidArgumentException(sprintf('%s.%d.data_nature must be one of: %s.', $field, $index, implode(', ', self::NATURES)));
            }

            $status = $record['data_status'] ?? null;
            if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
                throw new \InvalidArgumentException(sprintf('%s.%d.data_status must be one of: %s.', $field, $index, implode(', ', self::STATUSES)));
            }

            $periods[] = [
                'period' => self::validatedPeriod($record['period'] ?? null, sprintf('%s.%d.period', $field, $index)),
                'value_kwh' => $value,
                'data_nature' => $nature,
                'data_status' => $status,
                'quality' => self::validatedOptional($record['quality'] ?? null, self::QUALITIES, sprintf('%s.%d.quality', $field, $index)),
                'confidence_level' => self::validatedOptional($record['confidence_level'] ?? null, self::CONFIDENCES, sprintf('%s.%d.confidence_level', $field, $index)),
            ];
        }

        usort($periods, static function (array $a, array $b): int {
            return strcmp($a['period']['start'] ?? '~', $b['period']['start'] ?? '~');
        });

        return $periods;
    }

    /**
     * @return array{start: ?string, end: ?string, resolution: ?string}|null
     */
    private static function validatedPeriod(mixed $raw, string $field): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (!is_array($raw)) {
            throw new \InvalidArgumentException($field . ' must be an object with optional start, end and resolution.');
        }

        $period = ['start' => null, 'end' => null, 'resolution' => null];

        foreach (['start', 'end'] as $key) {
            $value = $raw[$key] ?? null;
            if ($value === null) {
                continue;
            }
            if (!is_string($value) || self::parseDate($value) === null) {
                throw new \InvalidArgumentException($field . '.' . $key . ' must be an ISO 8601 date or datetime (Y-m-d or Y-m-d\\TH:i:s).');
            }
            $period[$key] = $value;
        }

        $resolution = $raw['resolution'] ?? null;
        if ($resolution !== null) {
            if (!is_string($resolution) || $resolution === '' || mb_strlen($resolution) > 32) {
                throw new \InvalidArgumentException($field . '.resolution must be a short string such as HOUR, DAY or MONTH.');
            }
            $period['resolution'] = $resolution;
        }

        if ($period['start'] !== null && $period['end'] !== null
            && self::parseDate($period['start']) > self::parseDate($period['end'])) {
            throw new \InvalidArgumentException($field . '.start must not be after ' . $field . '.end.');
        }

        return $period;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function validatedBalanceResults(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw) || array_is_list($raw) === false) {
            throw new \InvalidArgumentException('balance must be a list of F4 result payloads.');
        }

        $results = [];
        foreach ($raw as $index => $payload) {
            if (!is_array($payload)) {
                throw new \InvalidArgumentException(sprintf('balance.%d must be an object.', $index));
            }

            $result = $payload['result'] ?? null;
            if (!is_array($result)) {
                throw new \InvalidArgumentException(sprintf('balance.%d.result must be an object.', $index));
            }

            $state = $result['state'] ?? null;
            if (!is_string($state) || !in_array($state, self::BALANCE_STATES, true)) {
                throw new \InvalidArgumentException(sprintf('balance.%d.result.state must be one of: %s.', $index, implode(', ', self::BALANCE_STATES)));
            }

            $classification = $result['classification'] ?? null;
            if ($state === 'CLASSIFIED') {
                if (!is_string($classification) || !in_array($classification, self::BALANCE_CLASSIFICATIONS, true)) {
                    throw new \InvalidArgumentException(sprintf('balance.%d.result.classification must be one of: %s when state is CLASSIFIED.', $index, implode(', ', self::BALANCE_CLASSIFICATIONS)));
                }
            } elseif ($classification !== null) {
                throw new \InvalidArgumentException(sprintf('balance.%d.result.classification must be null when state is UNAVAILABLE.', $index));
            }

            $balance = $result['balance_kwh'] ?? null;
            if ($balance !== null && (!is_int($balance) && !is_float($balance))) {
                throw new \InvalidArgumentException(sprintf('balance.%d.result.balance_kwh must be a number or null.', $index));
            }

            $results[] = [
                'period' => self::validatedPeriod($payload['period'] ?? null, sprintf('balance.%d.period', $index)),
                'result' => [
                    'state' => $state,
                    'classification' => $classification,
                    'balance_kwh' => $balance === null ? null : (float) $balance,
                    'data_nature' => self::validatedOptional($result['data_nature'] ?? null, self::NATURES, sprintf('balance.%d.result.data_nature', $index)),
                    'data_status' => self::validatedOptional($result['data_status'] ?? null, EvidenceSemantics::STATUSES, sprintf('balance.%d.result.data_status', $index)),
                    'quality' => self::validatedOptional($result['quality'] ?? null, self::QUALITIES, sprintf('balance.%d.result.quality', $index)),
                    'explanation' => self::validatedText($result['explanation'] ?? null, sprintf('balance.%d.result.explanation', $index)),
                ],
            ];
        }

        usort($results, static function (array $a, array $b): int {
            return strcmp($a['period']['start'] ?? '~', $b['period']['start'] ?? '~');
        });

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function validatedCostResults(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw) || array_is_list($raw) === false) {
            throw new \InvalidArgumentException('cost must be a list of F5 result payloads.');
        }

        $results = [];
        foreach ($raw as $index => $payload) {
            if (!is_array($payload)) {
                throw new \InvalidArgumentException(sprintf('cost.%d must be an object.', $index));
            }

            $result = $payload['result'] ?? null;
            if (!is_array($result)) {
                throw new \InvalidArgumentException(sprintf('cost.%d.result must be an object.', $index));
            }

            $state = $result['state'] ?? null;
            if (!is_string($state) || !in_array($state, self::COST_STATES, true)) {
                throw new \InvalidArgumentException(sprintf('cost.%d.result.state must be one of: %s.', $index, implode(', ', self::COST_STATES)));
            }

            $amount = $result['estimated_variable_cost'] ?? null;
            if ($amount !== null && (!is_int($amount) && !is_float($amount))) {
                throw new \InvalidArgumentException(sprintf('cost.%d.result.estimated_variable_cost must be a number or null.', $index));
            }

            $currency = $result['currency'] ?? null;
            if ($currency !== null && (!is_string($currency) || mb_strlen($currency) > 8)) {
                throw new \InvalidArgumentException(sprintf('cost.%d.result.currency must be a short currency code or null.', $index));
            }

            $pricingBasis = $result['pricing_basis'] ?? null;
            if ($pricingBasis !== null && (!is_string($pricingBasis) || $pricingBasis === '' || mb_strlen($pricingBasis) > 64)) {
                throw new \InvalidArgumentException(sprintf('cost.%d.result.pricing_basis must be a short string or null.', $index));
            }

            $results[] = [
                'period' => self::validatedPeriod($payload['period'] ?? null, sprintf('cost.%d.period', $index)),
                'result' => [
                    'state' => $state,
                    'cost_type' => self::validatedText($result['cost_type'] ?? null, sprintf('cost.%d.result.cost_type', $index), 32),
                    'estimated_variable_cost' => $amount === null ? null : (float) $amount,
                    'currency' => $currency,
                    'pricing_basis' => $pricingBasis,
                    'data_nature' => self::validatedOptional($result['data_nature'] ?? null, self::NATURES, sprintf('cost.%d.result.data_nature', $index)),
                    'data_status' => self::validatedOptional($result['data_status'] ?? null, EvidenceSemantics::STATUSES, sprintf('cost.%d.result.data_status', $index)),
                    'explanation' => self::validatedText($result['explanation'] ?? null, sprintf('cost.%d.result.explanation', $index)),
                ],
            ];
        }

        usort($results, static function (array $a, array $b): int {
            return strcmp($a['period']['start'] ?? '~', $b['period']['start'] ?? '~');
        });

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private static function validatedHistorical(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw) || count($raw) > 100) {
            throw new \InvalidArgumentException('historical must be an object with at most 100 top-level entries.');
        }

        return $raw;
    }

    /**
     * @return array{overall_status: ?string, overall_quality: ?string}
     */
    private static function validatedQualityContext(mixed $raw): array
    {
        if ($raw === null) {
            return ['overall_status' => null, 'overall_quality' => null];
        }

        if (!is_array($raw)) {
            throw new \InvalidArgumentException('quality must be an object.');
        }

        return [
            'overall_status' => self::validatedOptional($raw['overall_status'] ?? null, EvidenceSemantics::STATUSES, 'quality.overall_status'),
            'overall_quality' => self::validatedOptional($raw['overall_quality'] ?? null, self::QUALITIES, 'quality.overall_quality'),
        ];
    }

    /**
     * @return array{sources: array<int, string>, pipeline: array<int, string>}
     */
    private static function validatedProvenanceContext(mixed $raw): array
    {
        if ($raw === null) {
            return ['sources' => [], 'pipeline' => []];
        }

        if (!is_array($raw)) {
            throw new \InvalidArgumentException('provenance must be an object.');
        }

        return [
            'sources' => self::validatedStringList($raw['sources'] ?? [], 'provenance.sources'),
            'pipeline' => self::validatedStringList($raw['pipeline'] ?? [], 'provenance.pipeline'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function validatedStringList(mixed $raw, string $field): array
    {
        if (!is_array($raw) || array_is_list($raw) === false) {
            throw new \InvalidArgumentException($field . ' must be a list of short strings.');
        }

        $values = [];
        foreach ($raw as $index => $value) {
            if (!is_string($value) || $value === '' || mb_strlen($value) > 128) {
                throw new \InvalidArgumentException(sprintf('%s.%d must be a string of at most 128 characters.', $field, $index));
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param array<int, string> $allowed
     */
    private static function validatedOptional(mixed $raw, array $allowed, string $field): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (!is_string($raw) || !in_array($raw, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('%s must be one of: %s, or be omitted.', $field, implode(', ', $allowed)));
        }

        return $raw;
    }

    private static function validatedText(mixed $raw, string $field, int $maxLength = 1000): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (!is_string($raw) || mb_strlen($raw) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('%s must be a string of at most %d characters.', $field, $maxLength));
        }

        return $raw;
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        $formats = ['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d'];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format . '|', $value);
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        return null;
    }
}
