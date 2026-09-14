<?php

declare(strict_types=1);

/**
 * SPMS — MANUAL Mistral OCR controlled integration test (owner-run only).
 *
 * One document, one supervised round-trip against the official Mistral
 * API (Files API + /v1/ocr with a json_schema document annotation).
 * This is NOT the Bill Analyzer: nothing is persisted, no DB touch, no
 * Bedrock contact, no frontend, no business records.
 *
 * Usage (developer explicitly runs this once per bill):
 *   php scripts/manual-mistral-ocr-test.php --file="C:\path\to\bill.pdf"
 *   php scripts/manual-mistral-ocr-test.php --file="C:\path\to\bill.jpg"
 *
 * Safety:
 * - MISTRAL_API_KEY comes only from the local gitignored .env via
 *   Config; it is never printed. The key never appears in any output,
 *   exception text, or log written by this script.
 * - Signed Mistral file URLs are used internally and never printed.
 * - Unsupported/missing/oversized files fail before any network call.
 * - On API errors only safe normalized information is printed
 *   (status, sanitized mistral code, sanitized message).
 * - Automated PHPUnit never executes this script and never contacts
 *   Mistral.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\Mistral\MistralOcrClient;
use App\Services\Mistral\MistralOcrException;
use App\Support\Config;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

Config::initialize(dirname(__DIR__));

$filePath = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $filePath = substr($arg, strlen('--file='));
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Usage: php scripts/manual-mistral-ocr-test.php --file=\"PATH\"\n");
        exit(1);
    }
}

if ($filePath === null || $filePath === '') {
    fwrite(STDERR, "A single --file=\"PATH\" argument is required.\n");
    exit(1);
}

$annotationPrompt = 'This is a household electricity bill analysis task for a solar power management system. '
    . 'Extract only information visibly supported by the document. Do not calculate derived values during '
    . 'extraction. Do not treat cumulative meter readings as monthly consumption. Do not fabricate missing '
    . 'values. Preserve ambiguity and conflicting values. The backend will perform deterministic validation '
    . 'separately.';

$client = new MistralOcrClient();

if (!$client->hasApiKey()) {
    fwrite(STDERR, "FAILED [configuration] MISTRAL_API_KEY is not set in the local .env (never commit it).\n");
    exit(2);
}

try {
    $validated = MistralOcrClient::validateLocalDocument($filePath);

    if ($validated['mime'] === 'application/pdf') {
        // Official PDF route: Files API (purpose=ocr) → signed URL → /v1/ocr.
        $fileId = $client->uploadDocument($validated);
        $signedUrl = $client->fetchSignedUrl($fileId, 24);
        $document = MistralOcrClient::documentUrlRef($signedUrl);
        $route = 'pdf → Files API (purpose=ocr) → signed URL (withheld) → /v1/ocr';
    } else {
        // Image route: inline base64 data URL — no hosting requirement.
        $document = MistralOcrClient::imageDataUrlDocument($validated);
        $route = 'image → inline data URL → /v1/ocr';
    }

    $result = $client->processOcr($document, billAnnotationSchema(), $annotationPrompt);

    echo "MISTRAL OCR TEST\n";
    echo "----------------\n";
    echo 'File: ' . basename($validated['path']) . "\n";
    echo 'MIME: ' . $validated['mime'] . "\n";
    echo 'Size: ' . $validated['size'] . " bytes\n";
    echo 'Route: ' . $route . "\n";
    echo 'HTTP status: ' . ($client->lastHttpStatus() ?? '-') . "\n";
    echo 'Mistral model: ' . ($result['model'] ?? '-') . "\n";
    echo 'Pages processed: ' . count($result['pages']) . "\n";

    echo "\nDOCUMENT ASSESSMENT:\n";
    $annotation = $result['annotation'];
    if (!is_array($annotation)) {
        echo "  (no structured annotation returned" . ($result['annotation_error'] !== null ? ': ' . $result['annotation_error'] : '') . ")\n";
    } else {
        $assessment = $annotation['document_assessment'] ?? null;
        echo json_encode(is_array($assessment) ? $assessment : ['note' => 'document_assessment absent'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        echo "\nSTRUCTURED EXTRACTION:\n";
        echo json_encode($annotation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    echo "\nOCR TEXT:\n";
    foreach ($result['pages'] as $page) {
        echo "--- page " . ($page['index'] ?? '?')
            . " (blocks: " . ($page['blocks'] ?? '-') . ", tables: " . ($page['tables'] ?? '-') . ", images: " . $page['images'] . ") ---\n";
        echo $page['markdown'] !== '' ? $page['markdown'] : '(empty markdown)' ;
        echo "\n";
    }

    echo "\nCONFIDENCE:\n";
    $anyConfidence = false;
    foreach ($result['pages'] as $page) {
        if ($page['confidence_scores'] !== null) {
            $anyConfidence = true;
            echo 'page ' . ($page['index'] ?? '?') . ': '
                . json_encode($page['confidence_scores'], JSON_UNESCAPED_SLASHES) . "\n";
        }
    }
    if (!$anyConfidence) {
        echo "  (none returned in this response)\n";
    }

    echo "\nUSAGE: " . json_encode($result['usage'] ?? []) . "\n";
    exit(0);
} catch (MistralOcrException $e) {
    // Safe, normalized failure information only. Never the raw
    // provider payload, headers, URLs, or the API key.
    fwrite(STDERR, 'FAILED [' . $e->category()
        . ($e->mistralCode() !== null ? '/' . $e->mistralCode() : '')
        . ($e->status() !== null ? ' HTTP ' . $e->status() : '')
        . '] ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * SPMS-normalized extraction schema for THIS controlled test only
 * (per the agreed field set). Every value is nullable — absent means
 * null, never fabricated. Section-level confidence is requested;
 * deterministic validation stays backend-side later.
 *
 * @return array<string, mixed>
 */
function billAnnotationSchema(): array
{
    $nullableNumber = ['type' => ['number', 'null']];
    $nullableString = ['type' => ['string', 'null']];
    $confidence = ['type' => ['number', 'null']];

    $section = static function (array $properties) use ($confidence): array {
        $properties['confidence'] = $confidence;

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    };

    return [
        'type' => 'object',
        'properties' => [
            'document_assessment' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => ['string', 'null'],
                        'enum' => ['VALID', 'VALID_WITH_LIMITATIONS', 'NOT_A_BILL', 'UNREADABLE', 'UNCERTAIN', null],
                    ],
                    'reason' => $nullableString,
                    'confidence' => $confidence,
                ],
                'required' => ['status', 'reason', 'confidence'],
                'additionalProperties' => false,
            ],
            'billing_period' => $section(['start' => $nullableString, 'end' => $nullableString]),
            'consumption' => $section(['consumption_kwh' => $nullableNumber]),
            'meter_evidence' => $section([
                'previous_meter_reading' => $nullableNumber,
                'present_meter_reading' => $nullableNumber,
            ]),
            'solar' => $section([
                'previous_solar_reading' => $nullableNumber,
                'present_solar_reading' => $nullableNumber,
                'solar_units_kwh' => $nullableNumber,
                'net_units_kwh' => $nullableNumber,
            ]),
            'cost' => $section([
                'total_amount' => $nullableNumber,
                'energy_charges' => $nullableNumber,
                'fixed_charges' => $nullableNumber,
                'other_charges' => $nullableNumber,
                'customer_service_charges' => $nullableNumber,
                'taxes_adjustments' => $nullableNumber,
            ]),
            'context' => $section([
                'tariff_category' => $nullableString,
                'meter_type' => $nullableString,
                'contracted_load' => $nullableString,
                'utility_provider' => $nullableString,
            ]),
        ],
        'required' => ['document_assessment', 'billing_period', 'consumption', 'meter_evidence', 'solar', 'cost', 'context'],
        'additionalProperties' => false,
    ];
}
