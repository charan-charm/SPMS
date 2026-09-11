<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Energy\EnergyCostService;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;

/**
 * F5 — Energy Cost API (docs/02 §2, docs/01 §19, docs/06 §3, docs/10 D-042).
 *
 * POST /api/v1/energy/cost/estimate — compute an honest future estimated
 * cost from validated inputs and an explicitly declared pricing basis.
 * GET  /api/v1/energy/cost/historical — honest UNAVAILABLE until verified
 * bill persistence exists.
 *
 * The backend is the sole authority for numerical cost calculation; the
 * frontend presents results and never derives them. Fixed charges are
 * never multiplied by kWh and never folded into an inferred rate.
 */
final class EnergyCostController
{
    private const NATURES = ['ACTUAL', 'PREDICTED', 'ESTIMATED', 'SIMULATED'];
    private const STATUSES = ['FRESH', 'STALE', 'DEGRADED'];
    private const QUALITIES = ['VERIFIED', 'GOOD', 'PARTIAL', 'UNVERIFIED', 'POOR'];
    private const CONFIDENCE_LEVELS = ['HIGH', 'MODERATE', 'LOW', 'VERY_LOW'];
    private const PRICING_BASES = [
        EnergyCostService::BASIS_TARIFF_EVIDENCE,
        EnergyCostService::BASIS_HISTORICAL_BILL_RELATIONSHIP,
        EnergyCostService::BASIS_USER_PROVIDED_RATE,
        EnergyCostService::BASIS_INSUFFICIENT_EVIDENCE,
    ];
    private const CURRENCIES = [EnergyCostService::CURRENCY_INR];

    /** Sanity bounds — INR per kWh and INR amounts. */
    private const UNIT_RATE_MAX = 10_000.0;
    private const FIXED_CHARGE_MAX = 10_000_000.0;

    public function __construct(
        private readonly EnergyCostService $service = new EnergyCostService()
    ) {
    }

    public function estimate(Request $request): Response
    {
        $body = $request->body();

        $solar = $this->validatedInput($body['solar_generation'] ?? null, 'solar_generation');
        $consumption = $this->validatedInput($body['household_consumption'] ?? null, 'household_consumption');
        $pricing = $this->validatedPricing($body['pricing'] ?? null);
        $fixedCharge = $this->validatedFixedCharge($body['fixed_charge'] ?? null, $pricing);
        $period = $this->validatedPeriod($body['period'] ?? null);
        $householdId = $this->validatedHouseholdId($body['household_id'] ?? null);

        $result = $this->service->estimate(
            [
                'solar' => $solar,
                'consumption' => $consumption,
                'pricing' => $pricing,
                'fixed_charge' => $fixedCharge,
            ],
            $period,
            $householdId
        );

        return Response::success($result);
    }

    public function historical(Request $request): Response
    {
        $householdId = $this->validatedHouseholdId($request->queryParam('household_id'));

        return Response::success($this->service->historicalResult($householdId));
    }

    private function validatedInput(mixed $raw, string $field): array
    {
        if (!is_array($raw)) {
            throw new AppException(
                'VALIDATION_ERROR',
                sprintf('%s must be an object with value_kwh, data_nature and data_status.', $field),
                422,
                ['field' => $field]
            );
        }

        $value = $raw['value_kwh'] ?? null;
        if (!is_int($value) && !is_float($value)) {
            throw new AppException(
                'VALIDATION_ERROR',
                sprintf('%s.value_kwh must be a number.', $field),
                422,
                ['field' => $field . '.value_kwh']
            );
        }

        $value = (float) $value;
        if (!is_finite($value) || $value < 0.0 || $value > 1_000_000.0) {
            throw new AppException(
                'VALIDATION_ERROR',
                sprintf('%s.value_kwh must be a finite number between 0 and 1,000,000.', $field),
                422,
                ['field' => $field . '.value_kwh']
            );
        }

        $nature = $raw['data_nature'] ?? null;
        if (!is_string($nature) || !in_array($nature, self::NATURES, true)) {
            throw new AppException(
                'VALIDATION_ERROR',
                sprintf('%s.data_nature must be one of: %s.', $field, implode(', ', self::NATURES)),
                422,
                ['field' => $field . '.data_nature']
            );
        }

        $status = $raw['data_status'] ?? null;
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw new AppException(
                'VALIDATION_ERROR',
                sprintf('%s.data_status must be one of: %s.', $field, implode(', ', self::STATUSES)),
                422,
                ['field' => $field . '.data_status']
            );
        }

        $input = [
            'value_kwh' => $value,
            'nature' => $nature,
            'status' => $status,
        ];

        if (array_key_exists('quality', $raw)) {
            $quality = $raw['quality'];
            if ($quality === null) {
                throw new AppException(
                    'VALIDATION_ERROR',
                    sprintf('%s.quality must be one of: %s, or be omitted.', $field, implode(', ', self::QUALITIES)),
                    422,
                    ['field' => $field . '.quality']
                );
            }
            if (!is_string($quality) || !in_array($quality, self::QUALITIES, true)) {
                throw new AppException(
                    'VALIDATION_ERROR',
                    sprintf('%s.quality must be one of: %s.', $field, implode(', ', self::QUALITIES)),
                    422,
                    ['field' => $field . '.quality']
                );
            }
            $input['quality'] = $quality;
        }

        if (array_key_exists('confidence_level', $raw)) {
            $confidence = $raw['confidence_level'];
            if ($confidence === null) {
                throw new AppException(
                    'VALIDATION_ERROR',
                    sprintf('%s.confidence_level must be one of: %s, or be omitted.', $field, implode(', ', self::CONFIDENCE_LEVELS)),
                    422,
                    ['field' => $field . '.confidence_level']
                );
            }
            if (!is_string($confidence) || !in_array($confidence, self::CONFIDENCE_LEVELS, true)) {
                throw new AppException(
                    'VALIDATION_ERROR',
                    sprintf('%s.confidence_level must be one of: %s.', $field, implode(', ', self::CONFIDENCE_LEVELS)),
                    422,
                    ['field' => $field . '.confidence_level']
                );
            }
            $input['confidence_level'] = $confidence;
        }

        return $input;
    }

    /**
     * Pricing basis validation (F5.2/F5.3): an explicit whitelisted basis,
     * an explicit INR currency, and a unit rate only for computing bases.
     * Arbitrary pricing bases are never silently accepted.
     */
    private function validatedPricing(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new AppException(
                'VALIDATION_ERROR',
                'pricing must be an object with basis and currency.',
                422,
                ['field' => 'pricing']
            );
        }

        $basis = $raw['basis'] ?? null;
        if (!is_string($basis) || !in_array($basis, self::PRICING_BASES, true)) {
            throw new AppException(
                'VALIDATION_ERROR',
                sprintf('pricing.basis must be one of: %s.', implode(', ', self::PRICING_BASES)),
                422,
                ['field' => 'pricing.basis']
            );
        }

        $computing = $basis !== EnergyCostService::BASIS_INSUFFICIENT_EVIDENCE;

        $unitRate = $raw['unit_rate'] ?? null;
        if ($computing) {
            if (!is_int($unitRate) && !is_float($unitRate)) {
                throw new AppException(
                    'VALIDATION_ERROR',
                    'pricing.unit_rate must be a number for the declared pricing basis.',
                    422,
                    ['field' => 'pricing.unit_rate']
                );
            }

            $unitRate = (float) $unitRate;
            if (!is_finite($unitRate) || $unitRate <= 0.0 || $unitRate > self::UNIT_RATE_MAX) {
                throw new AppException(
                    'VALIDATION_ERROR',
                    sprintf('pricing.unit_rate must be a finite number greater than 0 and at most %s.', number_format(self::UNIT_RATE_MAX, 0, '', '')),
                    422,
                    ['field' => 'pricing.unit_rate']
                );
            }
        } elseif ($unitRate !== null) {
            throw new AppException(
                'VALIDATION_ERROR',
                'pricing.unit_rate must be omitted when pricing.basis is INSUFFICIENT_EVIDENCE.',
                422,
                ['field' => 'pricing.unit_rate']
            );
        }

        $currency = $raw['currency'] ?? null;
        if (!is_string($currency) || !in_array($currency, self::CURRENCIES, true)) {
            throw new AppException(
                'VALIDATION_ERROR',
                sprintf('pricing.currency must be one of: %s. Currency is explicit data in this version.', implode(', ', self::CURRENCIES)),
                422,
                ['field' => 'pricing.currency']
            );
        }

        $pricing = [
            'basis' => $basis,
            'currency' => $currency,
        ];

        if ($computing) {
            $pricing['unit_rate'] = (float) $unitRate;
        }

        if (array_key_exists('source', $raw) && $raw['source'] !== null) {
            $source = $raw['source'];
            if (!is_string($source) || $source === '' || mb_strlen($source) > 128) {
                throw new AppException(
                    'VALIDATION_ERROR',
                    'pricing.source must be a short string of at most 128 characters.',
                    422,
                    ['field' => 'pricing.source']
                );
            }
            $pricing['source'] = $source;
        }

        return $pricing;
    }

    /**
     * Fixed charge validation (F5.5): an optional standalone amount that is
     * echoed separately and never applied per kWh.
     */
    private function validatedFixedCharge(mixed $raw, array $pricing): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (!is_array($raw)) {
            throw new AppException(
                'VALIDATION_ERROR',
                'fixed_charge must be an object with amount and currency.',
                422,
                ['field' => 'fixed_charge']
            );
        }

        $amount = $raw['amount'] ?? null;
        if (!is_int($amount) && !is_float($amount)) {
            throw new AppException(
                'VALIDATION_ERROR',
                'fixed_charge.amount must be a number.',
                422,
                ['field' => 'fixed_charge.amount']
            );
        }

        $amount = (float) $amount;
        if (!is_finite($amount) || $amount < 0.0 || $amount > self::FIXED_CHARGE_MAX) {
            throw new AppException(
                'VALIDATION_ERROR',
                sprintf('fixed_charge.amount must be a finite number between 0 and %s.', number_format(self::FIXED_CHARGE_MAX, 0, '', '')),
                422,
                ['field' => 'fixed_charge.amount']
            );
        }

        $currency = $raw['currency'] ?? null;
        if (!is_string($currency) || !in_array($currency, self::CURRENCIES, true)) {
            throw new AppException(
                'VALIDATION_ERROR',
                sprintf('fixed_charge.currency must be one of: %s.', implode(', ', self::CURRENCIES)),
                422,
                ['field' => 'fixed_charge.currency']
            );
        }

        if ($currency !== $pricing['currency']) {
            throw new AppException(
                'VALIDATION_ERROR',
                'fixed_charge.currency must match pricing.currency.',
                422,
                ['field' => 'fixed_charge.currency']
            );
        }

        $fixedCharge = [
            'amount' => $amount,
            'currency' => $currency,
        ];

        if (array_key_exists('label', $raw) && $raw['label'] !== null) {
            $label = $raw['label'];
            if (!is_string($label) || $label === '' || mb_strlen($label) > 64) {
                throw new AppException(
                    'VALIDATION_ERROR',
                    'fixed_charge.label must be a short string of at most 64 characters.',
                    422,
                    ['field' => 'fixed_charge.label']
                );
            }
            $fixedCharge['label'] = $label;
        }

        return $fixedCharge;
    }

    private function validatedPeriod(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (!is_array($raw)) {
            throw new AppException(
                'VALIDATION_ERROR',
                'period must be an object with optional start, end and resolution.',
                422,
                ['field' => 'period']
            );
        }

        $period = [];

        foreach (['start', 'end'] as $key) {
            if (!array_key_exists($key, $raw) || $raw[$key] === null) {
                continue;
            }

            $value = $raw[$key];
            if (!is_string($value) || $this->parseDate($value) === null) {
                throw new AppException(
                    'VALIDATION_ERROR',
                    sprintf('period.%s must be an ISO 8601 date or datetime (Y-m-d or Y-m-d\\TH:i:s).', $key),
                    422,
                    ['field' => 'period.' . $key]
                );
            }

            $period[$key] = $value;
        }

        if (array_key_exists('resolution', $raw) && $raw['resolution'] !== null) {
            $resolution = $raw['resolution'];
            if (!is_string($resolution) || $resolution === '' || mb_strlen($resolution) > 32) {
                throw new AppException(
                    'VALIDATION_ERROR',
                    'period.resolution must be a short string such as HOUR, DAY or MONTH.',
                    422,
                    ['field' => 'period.resolution']
                );
            }
            $period['resolution'] = $resolution;
        }

        if ($period === []) {
            throw new AppException(
                'VALIDATION_ERROR',
                'period must include at least one of start, end or resolution.',
                422,
                ['field' => 'period']
            );
        }

        if (isset($period['start'], $period['end']) && $this->parseDate($period['start']) > $this->parseDate($period['end'])) {
            throw new AppException(
                'VALIDATION_ERROR',
                'period.start must not be after period.end.',
                422,
                ['field' => 'period.start']
            );
        }

        return $period;
    }

    private function validatedHouseholdId(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (!is_string($raw) || $raw === '' || mb_strlen($raw) > 64) {
            throw new AppException(
                'VALIDATION_ERROR',
                'household_id must be a string of at most 64 characters.',
                422,
                ['field' => 'household_id']
            );
        }

        return $raw;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
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
