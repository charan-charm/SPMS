<?php

declare(strict_types=1);

namespace App\Services\Mistral;

use App\Support\Config;
use GuzzleHttp\Client as GuzzleClient;
use Throwable;

/**
 * SPMS Mistral OCR provider (B3 production seam; docs/10 D-066 seam
 * philosophy, D-067 normalization rules, D-068 intake boundary).
 *
 * Evolved from the controlled integration test that was validated against
 * two real electricity bills; from B3 on it is the ONLY Mistral entry
 * point, consumed by the bill extraction workflow through
 * BillAnnotationNormalizer — no second client, no duplicated
 * HTTP/upload logic anywhere in the project.
 *
 * Contract verified against the official Mistral SDK docs (2026):
 *   POST /v1/files        multipart file + purpose=ocr          → {id}
 *   GET  /v1/files/{id}/url?expiry=N                            → {url}
 *   POST /v1/ocr          model + document (document_url |
 *                         image_url data-URL), include_blocks,
 *                         confidence_scores_granularity,
 *                         table_format, document_annotation_format
 *                         (json_schema) + document_annotation_prompt
 *                         → {model, pages[], document_annotation?,
 *                            usage_info}
 *
 * Design rules honored here:
 * - API key comes ONLY from SPMS Config (local gitignored .env); it is
 *   placed into the Authorization header inside this class and is never
 *   logged, returned, or embedded in exception text.
 * - HTTP goes through an injectable handler seam (same philosophy as
 *   BrevoMailTransport / BedrockAIProvider): production uses the
 *   already-installed Guzzle; automated tests inject fakes and can
 *   never contact Mistral. No new dependency was added.
 * - Raw provider responses are normalized to plain arrays; unknown or
 *   missing optional fields stay null — never fabricated. The client
 *   itself persists NOTHING (D-067: raw OCR never reaches storage).
 */
final class MistralOcrClient
{
    public const BASE_URL = 'https://api.mistral.ai';
    public const OCR_ENDPOINT = '/v1/ocr';
    public const FILES_ENDPOINT = '/v1/files';
    public const DEFAULT_MODEL = 'mistral-ocr-latest';
    public const TABLE_FORMAT = 'markdown';
    public const CONFIDENCE_GRANULARITY = 'block';

    /**
     * Bound for table CONTENT / markdown retained by the diagnostic
     * display — display hygiene, not silent truncation of the model's
     * answer (the truncation marker makes reduction visible).
     */
    public const MAX_DISPLAY_CHARS = 20_000;

    /** Local-document bound for this controlled test (25 MiB). */
    public const MAX_FILE_BYTES = 26_214_400;

    public const SUPPORTED_MIME_TYPES = ['application/pdf', 'image/png', 'image/jpeg'];

    /** @var callable(string, string, array<string, mixed>): array{status:int, body:string} */
    private $http;

    private readonly string $apiKey;
    private readonly string $model;

    private ?int $lastHttpStatus = null;

    /** Last observed HTTP status (safe metadata for the report). */
    public function lastHttpStatus(): ?int
    {
        return $this->lastHttpStatus;
    }

    /**
     * @param (callable(string, string, array<string, mixed>): array{status:int, body:string})|null $http
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?callable $http = null,
        private readonly string $baseUrl = self::BASE_URL
    ) {
        $resolvedKey = $apiKey;
        if ($resolvedKey === null || trim($resolvedKey) === '') {
            $configured = Config::get('MISTRAL_API_KEY');
            $resolvedKey = is_string($configured) ? $configured : '';
        }
        $this->apiKey = $resolvedKey;

        $resolvedModel = $model;
        if ($resolvedModel === null || trim($resolvedModel) === '') {
            $configuredModel = Config::get('MISTRAL_OCR_MODEL', self::DEFAULT_MODEL) ?? self::DEFAULT_MODEL;
            $resolvedModel = trim($configuredModel) !== '' ? trim($configuredModel) : self::DEFAULT_MODEL;
        }
        $this->model = $resolvedModel;

        $this->http = $http ?? self::guzzleHandler();
    }

    public function model(): string
    {
        return (string) $this->model;
    }

    public function hasApiKey(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== '';
    }

    // ---------------------------------------------------------------
    // SPMS bill annotation schema + prompt (CONTROLLED TEST ONLY —
    // this is the diagnostic harness's field set, not a production
    // Bill Analyzer contract; deterministic validation stays outside
    // the model).
    // ---------------------------------------------------------------

    /**
     * The instruction-ratified extraction prompt: never calculate,
     * never treat cumulative readings as consumption, never fabricate,
     * keep the three money concepts (bill amount / arrears / total due)
     * distinct and unreconstructable, preserve signs exactly, and map
     * the standard electricity-bill row labels to the correct normalized
     * semantics (cumulative evidence vs period quantities). The model
     * extracts what the document says — the backend validates.
     */
    public const BILL_ANNOTATION_PROMPT = 'This is a household electricity bill analysis task for a solar power management system. '
        . 'First decide whether this document IS a household electricity bill: if it is a logo, brand mark, unrelated photo or image, '
        . 'blank page, or a document of another kind, set document_assessment.status to NOT_A_BILL and return null for EVERY other '
        . 'field — never extract values from non-bill documents and never treat graphic or decorative text as bill data. '
        . 'Extract only information visibly supported by the document. Do not calculate derived values during extraction. '
        . 'Do not treat cumulative meter readings as monthly consumption. Do not fabricate missing values. '
        . 'Preserve ambiguity and conflicting values. The backend will perform deterministic validation separately. '
        . 'Extract exactly what the document states; never correct suspicious values — backend validation determines conflicts. '
        . 'KWH with PRESENT and PREVIOUS values represents cumulative meter readings. Map PRESENT to present_meter_reading '
        . 'and PREVIOUS to previous_meter_reading. Do not map either cumulative reading to monthly consumption. '
        . 'BILLED UNITS (equivalently labeled BILL UNITS, CONSUMPTION, or UNITS CONSUMED) represents the billing-period '
        . 'electricity consumption in kWh when present. Map the current billing-period BILLED UNITS value to consumption_kwh. '
        . 'Do not use the previous-period BILLED UNITS value as the current consumption. '
        . 'SOLARKWH with PRESENT and PREVIOUS values represents cumulative solar generation readings. Map PRESENT to '
        . 'present_solar_reading and PREVIOUS to previous_solar_reading. '
        . 'SOLAR UNITS represents the billing-period solar generation quantity. '
        . 'SOLAR EXPORT UNITS in the billing units table represents current-period solar export. Map the current-period '
        . 'SOLAR EXPORT UNITS value to solar_export_units_kwh and solar_units_kwh. '
        . 'Do NOT map an ambiguous, secondary, or bottom-of-bill figure (such as "Solar Units: 177") to solar_export_units_kwh '
        . 'or solar_units_kwh when a distinct SOLAR EXPORT UNITS row is present; instead, map any such separate ambiguous or '
        . 'unmapped figure to other_solar_balance_or_adjustment_kwh. '
        . 'NET UNITS represents the billing-period net energy quantity. Map the current-period NET UNITS value to '
        . 'net_units_kwh. Preserve its sign exactly. Do not replace a negative value with zero. '
        . 'Where a row contains current and previous values, the first/current value is the current billing period and the '
        . 'second value is the previous period unless the document explicitly states otherwise. '
        . 'Do not use arithmetic to invent missing extraction fields. Backend validation performs arithmetic separately. '
        . 'Billing period: when the document presents a current/end billing date followed by a previous/start billing date, '
        . 'normalize them chronologically so that start is the earlier date and end is the later date (start <= end). '
        . 'Never infer dates that are not visibly supported by the document. '
        . 'SERVICE NUMBER: when the document shows the electricity consumer service identifier, copy it EXACTLY as printed '
        . '(digits, letters and any separators) into service_number. Common labels include SERVICE NUMBER, SERVICE NO, '
        . 'SERVICE NO:, USC NO, USC NUMBER, CONSUMER SERVICE NUMBER and SON OF/DAUGHTER OF SERVICE lines naming the '
        . 'consumer number. Do not assume one exact label. Never use a bill number, invoice number, receipt number, meter '
        . 'number or meter serial, consumer name, mobile or phone number, Aadhaar or ID number, DTR or feeder code, '
        . 'check number, or any amount as the service number. When no explicit service identifier is shown, return null. '
        . 'Extract bill_amount, arrears and total_due as three DISTINCT fields: bill_amount is the explicit current-period '
        . 'bill amount (labels such as BILL AMT, BILL AMOUNT or AMOUNT FOR THIS PERIOD); arrears is the explicitly displayed '
        . 'past-dues amount with its sign preserved exactly as displayed (a credit may be negative); total_due is the '
        . 'explicit total payable now (labels such as TOTAL DUE, TOTAL AMOUNT DUE or AMOUNT PAYABLE). '
        . 'Never calculate or reconstruct any of the three from the others or from component charges — in particular never '
        . 'derive bill_amount from total_due and arrears. '
        . 'Keep bill_amount, arrears, total_due, energy_charges, fixed_charges, other_charges, customer_service_charges and '
        . 'taxes_adjustments as distinct fields; never collapse or merge them. Do not assume that an unexplained monetary '
        . 'number is other_charges merely because it is not classified elsewhere; when classification is ambiguous prefer null. '
        . 'If a value is not visibly supported, return null.';

    /**
     * SPMS-normalized extraction schema for this controlled test.
     * Every value is nullable — absent means null, never fabricated.
     * Section-level confidence is requested; the backend validates
     * deterministically afterwards.
     *
     * @return array<string, mixed>
     */
    public static function billAnnotationSchema(): array
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

        $billAmount = ['type' => ['number', 'null']];
        $billAmount['description'] = 'The document\'s explicit CURRENT-PERIOD bill amount (labels such as BILL AMT, BILL AMOUNT, '
            . 'AMOUNT FOR THIS PERIOD). Never reconstructed from total_due and arrears and never a sum of components. '
            . 'null when not explicitly shown.';

        $arrears = ['type' => ['number', 'null']];
        $arrears['description'] = 'The explicitly displayed past-dues / arrears amount, sign preserved exactly as shown '
            . '(a credit may be negative). Never inferred. null when the document shows no arrears line.';

        $totalDue = ['type' => ['number', 'null']];
        $totalDue['description'] = 'The explicitly displayed total payable now (labels such as TOTAL DUE, TOTAL AMOUNT DUE, '
            . 'AMOUNT PAYABLE). This is the document\'s own figure — NEVER calculated from bill_amount and arrears or from '
            . 'component charges. null when no explicit payable total is visible.';

        $consumptionKwh = ['type' => ['number', 'null']];
        $consumptionKwh['description'] = 'Billing-period electricity consumption in kWh, taken from the current-period '
            . 'BILLED UNITS row (or equivalently BILL UNITS / CONSUMPTION / UNITS CONSUMED). Never a cumulative KWH '
            . 'meter reading and never computed from meter deltas.';

        $previousReading = ['type' => ['number', 'null']];
        $previousReading['description'] = 'Cumulative meter reading for the PREVIOUS period (second value of the KWH row '
            . 'unless the document labels it otherwise). Evidence only; not a consumption quantity.';
        $presentReading = ['type' => ['number', 'null']];
        $presentReading['description'] = 'Cumulative meter reading for the PRESENT period (first/current value of the KWH '
            . 'row unless the document labels it otherwise). Evidence only; not a consumption quantity.';

        $previousSolar = ['type' => ['number', 'null']];
        $previousSolar['description'] = 'Cumulative solar generation reading for the previous period from the SOLARKWH row.';
        $presentSolar = ['type' => ['number', 'null']];
        $presentSolar['description'] = 'Cumulative solar generation reading for the present period from the SOLARKWH row.';
        $solarExportUnits = ['type' => ['number', 'null']];
        $solarExportUnits['description'] = 'Billing-period solar export quantity from the current-period SOLAR EXPORT UNITS row in the billing units table.';
        $solarUnits = ['type' => ['number', 'null']];
        $solarUnits['description'] = 'Billing-period solar export quantity from the current-period SOLAR EXPORT UNITS row; '
            . 'not a cumulative SOLARKWH reading and never an ambiguous bottom-of-bill figure.';
        $otherSolar = ['type' => ['number', 'null']];
        $otherSolar['description'] = 'Any separate, secondary, or bottom-of-bill solar balance/adjustment/unmapped figure '
            . '(such as Solar Units: 177) that is distinct from current-period SOLAR EXPORT UNITS. null when not present.';
        $netUnits = ['type' => ['number', 'null']];
        $netUnits['description'] = 'Billing-period net quantity from the current-period NET UNITS row. Sign must be '
            . 'preserved exactly as displayed; a negative value must never become zero.';

        $periodStart = ['type' => ['string', 'null']];
        $periodStart['description'] = 'Billing period START (the earlier date). When the document lists a current/end date '
            . 'followed by a previous/start date, normalize chronologically so start <= end. null when unsupported.';
        $periodEnd = ['type' => ['string', 'null']];
        $periodEnd['description'] = 'Billing period END (the later date), normalized chronologically against start. '
            . 'null when unsupported.';

        $serviceNumber = ['type' => ['string', 'null']];
        $serviceNumber['description'] = 'The electricity consumer SERVICE NUMBER / USC number exactly as printed (labels such as '
            . 'SERVICE NUMBER, SERVICE NO, USC NO, USC NUMBER, CONSUMER SERVICE NUMBER). Never a bill/invoice/receipt number, '
            . 'meter number, consumer name, phone/mobile, Aadhaar, DTR/feeder code or amount. null when no explicit service '
            . 'identifier is visible or the document is not a bill.';

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
                'billing_period' => $section(['start' => $periodStart, 'end' => $periodEnd]),
                'consumption' => $section(['consumption_kwh' => $consumptionKwh]),
                'meter_evidence' => $section([
                    'previous_meter_reading' => $previousReading,
                    'present_meter_reading' => $presentReading,
                ]),
                'solar' => $section([
                    'previous_solar_reading' => $previousSolar,
                    'present_solar_reading' => $presentSolar,
                    'solar_export_units_kwh' => $solarExportUnits,
                    'solar_units_kwh' => $solarUnits,
                    'other_solar_balance_or_adjustment_kwh' => $otherSolar,
                    'net_units_kwh' => $netUnits,
                ]),
                'cost' => [
                    'type' => 'object',
                    'properties' => [
                        'bill_amount' => $billAmount,
                        'arrears' => $arrears,
                        'total_due' => $totalDue,
                        'energy_charges' => $nullableNumber,
                        'fixed_charges' => $nullableNumber,
                        'other_charges' => $nullableNumber,
                        'customer_service_charges' => $nullableNumber,
                        'taxes_adjustments' => $nullableNumber,
                        'confidence' => $confidence,
                    ],
                    'required' => [
                        'bill_amount', 'arrears', 'total_due', 'energy_charges', 'fixed_charges', 'other_charges',
                        'customer_service_charges', 'taxes_adjustments', 'confidence',
                    ],
                    'additionalProperties' => false,
                ],
                'context' => $section([
                    'tariff_category' => $nullableString,
                    'meter_type' => $nullableString,
                    'contracted_load' => $nullableString,
                    'utility_provider' => $nullableString,
                    'service_number' => $serviceNumber,
                ]),
            ],
            'required' => ['document_assessment', 'billing_period', 'consumption', 'meter_evidence', 'solar', 'cost', 'context'],
            'additionalProperties' => false,
        ];
    }

    // ---------------------------------------------------------------
    // Local document validation (fail closed BEFORE any network call)
    // ---------------------------------------------------------------

    /**
     * @return array{path: string, mime: string, size: int, extension: string}
     */
    public static function validateLocalDocument(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw MistralOcrException::invalidRequest('Document not found or not readable: ' . basename($path));
        }

        $size = filesize($path);
        if ($size === false || $size < 1) {
            throw MistralOcrException::invalidRequest('Document is empty.');
        }

        if ($size > self::MAX_FILE_BYTES) {
            throw MistralOcrException::invalidRequest('Document exceeds the ' . self::MAX_FILE_BYTES . ' byte bound for this test.');
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $declared = match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };

        if ($declared === null || !in_array($declared, self::SUPPORTED_MIME_TYPES, true)) {
            throw MistralOcrException::invalidRequest('Unsupported document type (allowed: pdf, png, jpg, jpeg).');
        }

        // Content must agree with the extension — verified by the file
        // signature itself (this PHP build has no fileinfo extension,
        // and magic-byte checks are the strictest honest option for our
        // three exact formats). No silent conversion, no fabricated MIME.
        if (!self::signatureMatchesMime($path, $declared)) {
            throw MistralOcrException::invalidRequest(
                'Document content does not match its .' . $extension . ' extension.'
            );
        }

        return ['path' => $path, 'mime' => $declared, 'size' => $size, 'extension' => $extension];
    }

    /**
     * Magic-byte verification for the supported formats only:
     * PDF = "%PDF", PNG = 8-byte signature, JPEG = SOI marker.
     */
    private static function signatureMatchesMime(string $path, string $mime): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 8);
        fclose($handle);

        return match ($mime) {
            'application/pdf' => str_starts_with($head, '%PDF'),
            'image/png' => str_starts_with($head, "\x89PNG\r\n\x1a\n"),
            'image/jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            default => false,
        };
    }

    // ---------------------------------------------------------------
    // Request building (pure; unit-testable without HTTP)
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $document DocumentUnion-shaped reference
     * @param array<string, mixed>|null $annotationSchema
     * @return array<string, mixed>
     */
    public static function buildOcrRequest(string $model, array $document, ?array $annotationSchema = null, ?string $annotationPrompt = null): array
    {
        $request = [
            'model' => $model,
            'document' => $document,
            'table_format' => self::TABLE_FORMAT,
            'include_blocks' => true,
            'confidence_scores_granularity' => self::CONFIDENCE_GRANULARITY,
            // Explicitly NOT requesting image base64 output (first test).
            'include_image_base64' => false,
        ];

        if ($annotationSchema !== null) {
            $request['document_annotation_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'spms_bill_fields',
                    'strict' => false,
                    'schema' => $annotationSchema,
                ],
            ];

            if ($annotationPrompt !== null && trim($annotationPrompt) !== '') {
                $request['document_annotation_prompt'] = $annotationPrompt;
            }
        }

        return $request;
    }

    /**
     * @param array{path: string, mime: string, size: int, extension: string} $validated
     * @return array<string, mixed> image_url data-URL DocumentUnion member
     */
    public static function imageDataUrlDocument(array $validated): array
    {
        $bytes = file_get_contents($validated['path']);

        if ($bytes === false || $bytes === '') {
            throw MistralOcrException::invalidRequest('Document became unreadable.');
        }

        return [
            'type' => 'image_url',
            // Official inline form: image_url accepts a data URL string.
            'image_url' => 'data:' . $validated['mime'] . ';base64,' . base64_encode($bytes),
        ];
    }

    /**
     * @return array<string, mixed> document_url DocumentUnion member
     */
    public static function documentUrlRef(string $signedUrl): array
    {
        return ['type' => 'document_url', 'document_url' => $signedUrl];
    }

    // ---------------------------------------------------------------
    // API operations
    // ---------------------------------------------------------------

    /**
     * @param array{path: string, mime: string, size: int, extension: string} $validated
     */
    public function uploadDocument(array $validated): string
    {
        $bytes = file_get_contents($validated['path']);
        if ($bytes === false) {
            throw MistralOcrException::invalidRequest('Document became unreadable.');
        }

        $decoded = $this->call('POST', self::FILES_ENDPOINT, [
            'headers' => $this->authHeaders(),
            'multipart' => [
                ['name' => 'file', 'filename' => basename($validated['path']), 'content' => $bytes, 'headers' => ['Content-Type' => $validated['mime']]],
                ['name' => 'purpose', 'content' => 'ocr'],
            ],
            'timeout' => 120,
        ]);

        $id = $decoded['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw MistralOcrException::fromHttpStatus(200, [], null);
        }

        return $id;
    }

    public function fetchSignedUrl(string $fileId, int $expiryHours = 24): string
    {
        $expiryHours = max(1, min(168, $expiryHours));
        $decoded = $this->call('GET', self::FILES_ENDPOINT . '/' . rawurlencode($fileId) . '/url', [
            'headers' => $this->authHeaders(),
            'query' => ['expiry' => $expiryHours],
            'timeout' => 60,
        ]);

        $url = $decoded['url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw MistralOcrException::fromHttpStatus(200, [], null);
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed>|null $annotationSchema
     * @return array<string, mixed> normalized response (see parseOcrResponse)
     */
    public function processOcr(array $document, ?array $annotationSchema = null, ?string $annotationPrompt = null): array
    {
        $decoded = $this->call('POST', self::OCR_ENDPOINT, [
            'headers' => $this->authHeaders(['content-type' => 'application/json']),
            'json' => self::buildOcrRequest($this->model ?? self::DEFAULT_MODEL, $document, $annotationSchema, $annotationPrompt),
            'timeout' => 180,
        ]);

        return self::parseOcrResponse($decoded);
    }

    // ---------------------------------------------------------------
    // Response normalization (never fabricates; unknowns → null)
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    public static function parseOcrResponse(array $decoded): array
    {
        $pages = [];
        $rawPages = $decoded['pages'] ?? null;
        if (is_array($rawPages)) {
            foreach ($rawPages as $page) {
                if (!is_array($page)) {
                    continue;
                }

                $pages[] = [
                    'index' => isset($page['index']) && is_numeric($page['index']) ? (int) $page['index'] : null,
                    'markdown' => self::boundedDisplay(
                        isset($page['markdown']) && is_string($page['markdown']) ? $page['markdown'] : ''
                    ),
                    'images' => isset($page['images']) && is_array($page['images']) ? count($page['images']) : 0,
                    'tables' => self::parseTables($page['tables'] ?? null),
                    'blocks' => isset($page['blocks']) && is_array($page['blocks']) ? count($page['blocks']) : null,
                    'confidence_scores' => isset($page['confidence_scores']) && is_array($page['confidence_scores'])
                        ? self::sanitizeNumeric($page['confidence_scores'])
                        : null,
                ];
            }
        }

        $annotation = self::parseAnnotation($decoded['document_annotation'] ?? null);

        $usage = isset($decoded['usage_info']) && is_array($decoded['usage_info'])
            ? self::sanitizeNumeric($decoded['usage_info'])
            : null;

        return [
            'model' => isset($decoded['model']) && is_string($decoded['model']) ? $decoded['model'] : null,
            'pages' => $pages,
            'annotation' => $annotation['value'],
            'annotation_error' => $annotation['error'],
            'usage' => $usage,
        ];
    }

    /**
     * Official OCRTableObject: {id, content, format, word_confidence_scores?}.
     * `content` holds the ACTUAL extracted table in the requested format
     * (the page markdown only carries a `[tbl-N.md](tbl-N.md)`-style
     * placeholder pointing at it). Never reconstructed, never invented.
     *
     * @return array<int, array{id: string|null, content: string, format: string|null}>|null
     *         null when the response carried no tables field at all.
     */
    private static function parseTables(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $tables = [];
        foreach ($raw as $table) {
            if (!is_array($table)) {
                continue;
            }

            $tables[] = [
                'id' => isset($table['id']) && is_string($table['id']) ? $table['id'] : null,
                'content' => self::boundedDisplay(
                    isset($table['content']) && is_string($table['content']) ? $table['content'] : ''
                ),
                'format' => match (true) {
                    isset($table['format']) && is_string($table['format']) => $table['format'],
                    isset($table['format_']) && is_string($table['format_']) => $table['format_'],
                    default => null,
                },
            ];
        }

        return $tables;
    }

    /** Table count helper for display summaries. */
    public static function tableCount(?array $parsedTables): int
    {
        return $parsedTables === null ? 0 : count($parsedTables);
    }

    /** Bounded, display-safe retained content with a visible truncation marker. */
    private static function boundedDisplay(string $text): string
    {
        if (strlen($text) <= self::MAX_DISPLAY_CHARS) {
            return $text;
        }

        return substr($text, 0, self::MAX_DISPLAY_CHARS) . "\n…[truncated at " . self::MAX_DISPLAY_CHARS . ' chars]';
    }

    /**
     * document_annotation arrives as a JSON STRING per the official
     * contract; malformed JSON is reported, never repaired or guessed.
     *
     * @return array{value: array<mixed>|null, error: string|null}
     */
    public static function parseAnnotation(mixed $raw): array
    {
        if ($raw === null) {
            return ['value' => null, 'error' => null];
        }

        if (is_array($raw)) {
            return ['value' => $raw, 'error' => null];
        }

        if (!is_string($raw) || trim($raw) === '') {
            return ['value' => null, 'error' => 'document_annotation present but not a usable string'];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['value' => null, 'error' => 'document_annotation JSON did not parse (' . json_last_error_msg() . ')'];
        }

        return ['value' => $decoded, 'error' => null];
    }

    /**
     * Keep scalars that are numeric/bool/string-flag metadata only;
     * drop anything untrusted deeply (defensive against oversized or
     * nested junk in the payload).
     *
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    private static function sanitizeNumeric(array $input, int $depth = 0): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            if (is_array($value)) {
                if ($depth < 4) {
                    $keyStr = is_string($key) && preg_match('/^[\w.\-]{1,64}$/', $key) === 1 ? $key : (string) array_key_last($out) . '_';
                    $out[$keyStr] = self::sanitizeNumeric($value, $depth + 1);
                }
                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $options): array
    {
        if (!$this->hasApiKey()) {
            throw MistralOcrException::configuration('MISTRAL_API_KEY is not configured (local .env only; never committed).');
        }

        $url = rtrim($this->baseUrl, '/') . $path;

        try {
            /** @var array{status:int, body:string} $response */
            $response = ($this->http)($method, $url, $options);
        } catch (Throwable $e) {
            // Transport-level failure only. The underlying exception is
            // retained for diagnostics; Guzzle request strings can
            // contain headers, so they never reach our message.
            throw MistralOcrException::network($e);
        }

        $status = (int) ($response['status'] ?? 0);
        $bodyRaw = (string) ($response['body'] ?? '');
        $this->lastHttpStatus = $status;

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($bodyRaw, true);
            throw MistralOcrException::fromHttpStatus($status, is_array($decoded) ? $decoded : []);
        }

        $decoded = json_decode($bodyRaw, true);
        if (!is_array($decoded)) {
            throw MistralOcrException::fromHttpStatus($status, ['message' => 'response body was not valid JSON']);
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function authHeaders(array $extra = []): array
    {
        return array_merge(
            [
                'authorization' => 'Bearer ' . (string) $this->apiKey,
                'accept' => 'application/json',
            ],
            $extra
        );
    }

    /**
     * Production default handler: the already-installed Guzzle client
     * (no new dependency). http_errors stays enabled=false so status
     * handling remains ours — and Guzzle never throws into our messages.
     *
     * @return callable(string, string, array<string, mixed>): array{status:int, body:string}
     */
    private static function guzzleHandler(): callable
    {
        return static function (string $method, string $url, array $options): array {
            $options['http_errors'] = false;
            $options['connect_timeout'] = $options['connect_timeout'] ?? 10.0;
            $options['timeout'] = $options['timeout'] ?? 45.0;
            $client = new GuzzleClient();
            $response = $client->request($method, $url, $options);

            return [
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getBody(),
            ];
        };
    }
}
