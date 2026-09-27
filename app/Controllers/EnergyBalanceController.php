<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillStatus;
use App\Domain\Energy\EnergyBalanceService;
use App\Domain\Forecast\ForecastHorizon;
use App\Domain\Forecast\ForecastService;
use App\Domain\Household\HouseholdService;
use App\Repositories\BillRepository;
use App\Repositories\SolarConnectionRepository;
use App\Repositories\SolarReadingRepository;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;

/**
 * F4 — Energy Balance API (docs/02 §2, docs/04 §60, docs/06 §13).
 *
 * POST /api/v1/energy/balance — classify a balance from provided inputs.
 * GET  /api/v1/energy/balance — evaluates active energy balance from verified evidence.
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

    private ?HouseholdService $households = null;
    private ?ForecastService $forecastService = null;
    private ?SolarConnectionRepository $solarConnections = null;
    private ?SolarReadingRepository $solarReadings = null;
    private ?BillRepository $bills = null;

    public function __construct(
        private readonly EnergyBalanceService $service = new EnergyBalanceService(),
        ?HouseholdService $households = null,
        ?ForecastService $forecastService = null,
        ?SolarConnectionRepository $solarConnections = null,
        ?SolarReadingRepository $solarReadings = null,
        ?BillRepository $bills = null
    ) {
        $this->households = $households;
        $this->forecastService = $forecastService;
        $this->solarConnections = $solarConnections;
        $this->solarReadings = $solarReadings;
        $this->bills = $bills;
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
        $account = Authorization::currentAccount($request);
        $householdId = null;

        if ($account !== null) {
            $activeHh = $this->households()->activeHouseholdId($account);
            $queryHh = $this->validatedHouseholdId($request->queryParam('household_id'));
            if ($queryHh !== null && $queryHh !== $activeHh) {
                throw new AppException('ACCESS_DENIED', 'You do not have access to this household.', 403);
            }
            $householdId = $activeHh;
        } else {
            $queryHh = $this->validatedHouseholdId($request->queryParam('household_id'));
            if ($queryHh !== null) {
                return Response::success($this->service->unavailableResult(null));
            }
        }

        if ($householdId === null) {
            return Response::success($this->service->unavailableResult(null));
        }

        // Query connected evidence for the household
        $solarConn = $this->solarConnections()->findByHouseholdId($householdId);
        $solarReadingsCount = $this->solarReadings()->countByHouseholdId($householdId);
        $hasSolar = ($solarConn !== null && in_array($solarConn['connection_status'] ?? '', ['CONNECTED', 'OFFLINE', 'DEGRADED', 'STALE'], true))
            || $solarReadingsCount > 0;

        $allBills = $this->bills()->listForHousehold($householdId);
        $verifiedBills = array_filter(
            $allBills,
            static fn ($b): bool => ($b instanceof Bill ? $b->status() === BillStatus::VERIFIED : ($b['status'] ?? '') === 'VERIFIED')
        );
        $hasConsumption = count($verifiedBills) > 0;

        if (!$hasSolar && !$hasConsumption) {
            return Response::success($this->service->unavailableResult(
                $householdId,
                'No solar generation or household consumption data sources are connected yet, so no energy balance can be calculated.',
                limitation: 'A balance can be calculated here once solar generation and household consumption data sources are connected.'
            ));
        }

        if ($hasSolar && !$hasConsumption) {
            return Response::success($this->service->unavailableResult(
                $householdId,
                'Household consumption history has no verified electricity bills yet. Once bills are verified, an energy balance will be calculated.',
                notes: [
                    'Energy balance requires solar generation and household consumption data. Solar inverter is connected, but verified consumption data is needed.',
                    'A balance result is a potential energy situation, never confirmed grid import/export.',
                ],
                limitation: 'Upload and verify your recent electricity bills to calculate your energy balance.'
            ));
        }

        if (!$hasSolar && $hasConsumption) {
            return Response::success($this->service->unavailableResult(
                $householdId,
                'Solar inverter is not connected yet. Once connected and transmitting generation telemetry, an energy balance will be calculated.',
                notes: [
                    'Energy balance requires solar generation and household consumption data. Verified electricity bills exist, but solar generation data is needed.',
                    'A balance result is a potential energy situation, never confirmed grid import/export.',
                ],
                limitation: 'Connect your solar inverter on the Solar page to calculate your energy balance.'
            ));
        }

        // Both solar and verified consumption exist: evaluate active Day-Ahead balance
        $forecast = $this->forecastService()->getForecast($householdId, ForecastHorizon::DAY_AHEAD);

        $solarSnapshot = $forecast['solar_forecast'] ?? null;
        $consumptionSnapshot = $forecast['consumption_forecast'] ?? null;

        if (
            $solarSnapshot === null ||
            $consumptionSnapshot === null ||
            !isset($solarSnapshot['predicted_value']) ||
            !isset($consumptionSnapshot['predicted_value']) ||
            ($consumptionSnapshot['confidence'] ?? '') === 'INSUFFICIENT'
        ) {
            return Response::success($this->service->unavailableResult(
                $householdId,
                $forecast['energy_balance_projection']['explanation']
                    ?? 'Energy balance cannot be calculated due to insufficient forecast evidence.'
            ));
        }

        $solarValue = (float) $solarSnapshot['predicted_value'];
        $consumptionValue = (float) $consumptionSnapshot['predicted_value'];

        $inputs = [
            'solar' => [
                'value_kwh' => $solarValue,
                'nature' => $solarSnapshot['data_nature'] ?? 'PREDICTED',
                'status' => 'FRESH',
                'quality' => $solarSnapshot['data_quality'] ?? 'VERIFIED',
                'confidence_level' => $solarSnapshot['confidence'] ?? 'LOW',
            ],
            'consumption' => [
                'value_kwh' => $consumptionValue,
                'nature' => $consumptionSnapshot['data_nature'] ?? 'PREDICTED',
                'status' => 'FRESH',
                'quality' => $consumptionSnapshot['data_quality'] ?? 'VERIFIED',
                'confidence_level' => $consumptionSnapshot['confidence'] ?? 'MODERATE',
            ],
        ];

        $classified = $this->service->classify(
            $inputs,
            EnergyBalanceService::ANALYSIS_TIME_PERIOD,
            $solarSnapshot['period'] ?? null,
            $householdId
        );

        return Response::success($classified);
    }

    private function households(): HouseholdService
    {
        return $this->households ??= new HouseholdService();
    }

    private function forecastService(): ForecastService
    {
        return $this->forecastService ??= new ForecastService();
    }

    private function solarConnections(): SolarConnectionRepository
    {
        return $this->solarConnections ??= new SolarConnectionRepository();
    }

    private function solarReadings(): SolarReadingRepository
    {
        return $this->solarReadings ??= new SolarReadingRepository();
    }

    private function bills(): BillRepository
    {
        return $this->bills ??= new BillRepository();
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
