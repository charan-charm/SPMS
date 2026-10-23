<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Detectors;

use App\Domain\Intelligence\DetectedCandidate;
use App\Domain\Intelligence\InsightEvidenceInput;
use App\Domain\Intelligence\InsightSignificance;
use App\Domain\Intelligence\InsightTaxonomy;

/**
 * SPMS Solar Hardware Intelligence — Inverter & Telemetry Detector (F6/F8).
 *
 * Evaluates hardware telemetry freshness and operational connection status:
 * - Detects stale solar inverter telemetry (>15-30 mins old during daylight) (Rule 9G, 7).
 * - Detects persistent inverter disconnection or offline status (Rule 9F).
 * - Never substitutes zero for unavailable or stale telemetry.
 * - Explains honestly that live generation cannot be confirmed.
 */
final class SolarHardwareDetector implements InsightDetector
{
    public const EXPLANATION_SOURCE = 'SOLAR_HARDWARE_DETECTOR_V1';

    public function detect(InsightEvidenceInput $evidence): array
    {
        $conn = $evidence->solarConnection();
        if ($conn === null) {
            return [];
        }

        $candidates = [];

        $inverterStatus = strtoupper(trim((string) ($conn['inverter_status'] ?? $conn['status'] ?? '')));
        $dataStatus = strtoupper(trim((string) ($conn['data_status'] ?? 'FRESH')));
        $lastTelemetry = $conn['last_telemetry_at'] ?? null;
        $nature = (string) ($conn['data_nature'] ?? 'ACTUAL');

        // 1. Inverter Disconnected / Fault / Offline
        if (in_array($inverterStatus, ['OFFLINE', 'FAULT', 'DISCONNECTED'], true)) {
            $facts = [
                sprintf('Inverter communication status reported: %s.', $inverterStatus),
                $lastTelemetry !== null
                    ? sprintf('Last telemetry timestamp recorded: %s UTC.', $lastTelemetry)
                    : 'No telemetry timestamp on record.',
                sprintf('Hardware connection ID / Station: %s.', $conn['station_id'] ?? 'configured solar connection'),
            ];

            $candidates[] = new DetectedCandidate(
                type: InsightTaxonomy::TYPE_INVERTER_DISCONNECTED,
                title: 'Solar Inverter Communication Offline',
                summary: 'Solar hardware communication is currently offline or reporting an inverter fault. Live generation monitoring is suspended.',
                affectedPeriod: null,
                supportingFacts: $facts,
                limitations: [
                    'Solar power and energy values cannot be read until inverter connectivity is restored.',
                    'Offline status is never treated as zero generation.',
                ],
                evidenceSummary: 'Solar hardware connectivity status evaluation.',
                explanation: 'The GoodWe solar inverter or data logger has stopped transmitting real-time operational status. Real-time energy balance and live production monitoring are paused until communication recovers.',
                explanationSource: self::EXPLANATION_SOURCE,
                evidenceDomains: ['solar'],
                natures: [$nature],
                statuses: ['DEGRADED'],
                qualities: ['UNVERIFIED'],
                detectors: [self::EXPLANATION_SOURCE],
                significanceHint: InsightSignificance::HIGH,
                comparisonCount: null
            );
        }

        // 2. Solar Telemetry Stale
        if ($dataStatus === 'STALE' && !in_array($inverterStatus, ['OFFLINE', 'FAULT', 'DISCONNECTED'], true)) {
            $facts = [
                'Inverter telemetry age exceeds the configured freshness threshold (>15 minutes).',
                $lastTelemetry !== null
                    ? sprintf('Latest received telemetry timestamp: %s UTC.', $lastTelemetry)
                    : 'Telemetry timestamp is not current.',
                sprintf('Data nature: %s (status: %s).', $nature, $dataStatus),
            ];

            $candidates[] = new DetectedCandidate(
                type: InsightTaxonomy::TYPE_SOLAR_TELEMETRY_STALE,
                title: 'Solar Inverter Telemetry is Delayed',
                summary: 'The latest inverter reading is older than the configured freshness limit. Real-time solar production cannot currently be confirmed.',
                affectedPeriod: null,
                supportingFacts: $facts,
                limitations: [
                    'Recommendations and energy balance calculations that depend on current production are temporarily constrained.',
                    'Delayed telemetry is kept as stale and never silently replaced with zero.',
                ],
                evidenceSummary: 'Solar telemetry freshness evaluation.',
                explanation: 'Inverter telemetry has not updated within the expected interval. Live power readings may reflect older generation values until fresh measurements arrive.',
                explanationSource: self::EXPLANATION_SOURCE,
                evidenceDomains: ['solar'],
                natures: [$nature],
                statuses: ['STALE'],
                qualities: ['PARTIAL'],
                detectors: [self::EXPLANATION_SOURCE],
                significanceHint: InsightSignificance::MODERATE,
                comparisonCount: null
            );
        }

        return $candidates;
    }
}
