<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Energy\EnergyBalanceService;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;

/**
 * F4 — Energy Balance API (docs/02 §2, docs/04 §60, docs/06 §13).
 *
 * POST /api/v1/energy/balance — classify a balance from provided inputs.
 * GET  /api/v1/energy/balance — honest UNAVAILABLE until data sources exist.
 *
 * The backend is the sole authority for classification; the frontend
 * presents results and never derives them.
 */
final class EnergyBalanceController
{
    private const NATURES = ['ACTUAL', 'PREDICTED', 'ESTIMATED', 'SIMULATED'];
    private const STATUSES = ['FRESH', 'STALE', 'DEGRADED'];
    private const QUALITIES = ['VERIFIED', 'GOOD', 'PARTIAL', 'UNVERIFIED', 'POOR'];
    private const CONFIDENCE_LEVELS = ['HIGH', 'MODERATE', 'LOW', 'VERY_LOW'];
    private const ANALYSIS_TYPES = ['TIME_PERIOD', 'AGGREGATE'];

    public function __construct(
        private readonly EnergyBalanceService $service = new EnergyBalanceService()
    ) {
    }

    public function classify(Request $request): Response
    {
        $body = $request->body();

        $solar = $this->validatedInput($body['solar_generation'] ?? null, 'solar_generation');
        $consumption = $this->validatedInput($body['household_consumption'] ?? null, 'household_consumption');

        $analysisType = $body['analysis_type'] ?? EnergyBalanceService::ANALYSIS_AGGREGATE;
        if (!is_string($analysisType) || !in_array($analysisType, self::ANALYSIS_TYPES, true)) {
            throw new AppException(
                'VALIDATION_ERROR',
                'analysis_type must be TIME_PERIOD or AGGREGATE.',
                422,
                ['field' => 'analysis_type']
            );
        }

        $period = $this->validatedPeriod($body['period'] ?? null);
        $householdId = $this->validatedHouseholdId($body['household_id'] ?? null);

        $result = $this->service->classify(
            ['solar' => $solar, 'consumption' => $consumption],
            $analysisType,
            $period,
            $householdId
        );

        return Response::success($result);
    }

    public function current(Request $request): Response
    {
        $householdId = $this->validatedHouseholdId($request->queryParam('household_id'));

        return Response::success($this->service->unavailableResult($householdId));
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
                    sprintf('period.%s must be an ISO 8601 date or datetime (Y-m-d or Y-m-d\TH:i:s).', $key),
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
