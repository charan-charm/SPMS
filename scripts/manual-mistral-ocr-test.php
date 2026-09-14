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

use App\Services\Mistral\MistralBillValidator;
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

$annotationPrompt = MistralOcrClient::BILL_ANNOTATION_PROMPT;

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

    $result = $client->processOcr($document, MistralOcrClient::billAnnotationSchema(), $annotationPrompt);

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
            . " (blocks: " . ($page['blocks'] ?? '-') . ", tables: " . MistralOcrClient::tableCount($page['tables']) . ", images: " . $page['images'] . ") ---\n";
        echo $page['markdown'] !== '' ? $page['markdown'] : '(empty markdown)';
        echo "\n";
    }

    // Tables are rendered from the ACTUAL returned content (official
    // OCRTableObject.content); the page markdown placeholder like
    // [tbl-0.md] alone is never the evidence. Nothing is reconstructed.
    echo "\nTABLES:\n";
    $anyTable = false;
    foreach ($result['pages'] as $page) {
        foreach ($page['tables'] ?? [] as $position => $table) {
            $anyTable = true;
            echo '--- page ' . ($page['index'] ?? '?') . ' / table '
                . ($table['id'] ?? ('#' . $position))
                . (isset($table['format']) && is_string($table['format']) ? ' (format: ' . $table['format'] . ')' : '')
                . " ---\n";
            echo $table['content'] !== '' ? $table['content'] : '(empty table content returned)';
            echo "\n";
        }
    }
    if (!$anyTable) {
        echo "  (no table content returned)\n";
    }

    echo "\nDETERMINISTIC VALIDATION:\n";
    echo "-------------------------\n";
    $validation = MistralBillValidator::validate(is_array($annotation) ? $annotation : null);
    $labels = [
        'meter' => 'Meter consumption relationship',
        'solar' => 'Solar production relationship',
        'net' => 'Net units relationship',
    ];
    foreach ($validation as $key => $check) {
        echo $labels[$key] . ': ' . $check['status'];
        if ($check['status'] === MistralBillValidator::PASS) {
            echo "\n";
            continue;
        }

        echo "\n";
        foreach ($check as $field => $value) {
            if ($field === 'status' || !is_scalar($value)) {
                continue;
            }

            echo '  ' . $field . '=' . (is_float($value) ? rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') : (string) $value) . "\n";
        }
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
