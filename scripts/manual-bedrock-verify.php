<?php

declare(strict_types=1);

/**
 * AI foundation (docs/10 D-066) — MANUAL Bedrock verification tool.
 *
 * The ONLY sanctioned path for SPMS to make a real Amazon Bedrock
 * Runtime (Converse) call. Same safety philosophy as the Brevo manual
 * entry point and the GoodWe research probe — with one deliberate
 * difference: this script NEVER enables the guard itself. The owner
 * must explicitly set BEDROCK_ALLOW_REAL_CALLS=1 in the local,
 * gitignored .env, and pass the confirmation token on the command
 * line. Automated tests pin the flag off and inject fakes instead.
 *
 * Usage (text only, one tiny deterministic request):
 *   php scripts/manual-bedrock-verify.php --confirm=BEDROCK-CONVERSE-VERIFY
 *
 * Bare diagnostic shape (omits inferenceConfig entirely — the smallest
 * request the installed Converse model permits, used to isolate
 * provider-side parameter validation):
 *   php scripts/manual-bedrock-verify.php --confirm=BEDROCK-CONVERSE-VERIFY --bare
 *
 * Optional single text+image round-trip (explicit local file only;
 * JPEG/PNG, never URLs, never automatic):
 *   php scripts/manual-bedrock-verify.php --confirm=BEDROCK-CONVERSE-VERIFY --image=path\to\sample.jpg
 *
 * Output is limited to safe, normalized information: provider, model,
 * region, correlation id, stop reason, usage counters and the model's
 * answer text. Credentials, authorization material, raw SDK objects
 * and provider error internals are never printed.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\AI\AIException;
use App\Services\AI\AIImage;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\AIRequest;
use App\Services\AI\BedrockAIProvider;
use App\Support\Config;
use App\Support\Logger;
use App\Support\RequestId;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

Config::initialize(dirname(__DIR__));
RequestId::set(RequestId::new());

$argvCopy = $argv;
array_shift($argvCopy);

$confirm = null;
$imagePath = null;
$bare = false;
$prompt = 'Reply with exactly one word: ready';

foreach ($argvCopy as $arg) {
    if (str_starts_with($arg, '--confirm=')) {
        $confirm = substr($arg, strlen('--confirm='));
    } elseif (str_starts_with($arg, '--image=')) {
        $imagePath = substr($arg, strlen('--image='));
    } elseif (str_starts_with($arg, '--text=')) {
        $prompt = substr($arg, strlen('--text='));
    } elseif ($arg === '--bare') {
        // Diagnostic shape: no inferenceConfig at all (the request
        // members Bedrock's model declares fully optional). Used to
        // isolate provider-side parameter validation failures.
        $bare = true;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

$flag = Config::get('BEDROCK_ALLOW_REAL_CALLS');
if ($flag !== '1') {
    fwrite(STDERR, "REFUSING: real Bedrock calls require BEDROCK_ALLOW_REAL_CALLS=1 in the local .env (this script never sets it).\n");
    exit(1);
}

if ($confirm !== 'BEDROCK-CONVERSE-VERIFY') {
    fwrite(STDERR, "REFUSING: pass --confirm=BEDROCK-CONVERSE-VERIFY to acknowledge one deliberate paid provider request.\n");
    exit(1);
}

$region = Config::get('AWS_REGION', BedrockAIProvider::DEFAULT_REGION) ?? BedrockAIProvider::DEFAULT_REGION;
$model = Config::get('AWS_BEDROCK_MODEL_ID', BedrockAIProvider::DEFAULT_MODEL_ID) ?? BedrockAIProvider::DEFAULT_MODEL_ID;

$logger = new Logger(dirname(__DIR__) . '/storage/logs', Config::get('APP_LOG_LEVEL', 'warning') ?? 'warning');

echo "Provider : bedrock (Converse)\n";
echo "Region   : {$region}\n";
echo "Model    : {$model}\n";
echo "Correl.  : " . RequestId::current() . "\n";

try {
    $request = $imagePath === null
        ? AIRequest::text($prompt)
        : AIRequest::textWithImages($prompt, [manualImageFromFile($imagePath)]);

    if ($bare) {
        $request = $request->withoutInferenceConfig();
    }
    echo "Shape    : " . ($bare ? 'bare (no inferenceConfig)' : 'default (bounded inferenceConfig)') . "\n";

    $provider = new BedrockAIProvider(null, $logger);
    $factory = new AIProviderFactory($provider);
    $response = $factory->complete($request);

    echo "Status   : success\n";
    echo "Stop     : " . ($response->stopReason() ?? '-') . "\n";
    $usage = $response->usage();
    echo "Usage    : " . ($usage === null ? 'not reported' : json_encode($usage)) . "\n";
    echo "Answer   :\n" . $response->textOrStructuredJson() . "\n";
    exit(0);
} catch (AIException $e) {
    // AIException messages are constructed safe by this codebase:
    // fixed text, no credentials, no provider internals. The AWS
    // error CODE (when present) is sanitized machine metadata.
    $detail = $e->providerCode();
    if ($e->awsErrorCode() !== null) {
        $detail .= '/' . $e->awsErrorCode();
    }
    fwrite(STDERR, 'FAILED [' . $detail . '] ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Diagnostics: check storage/logs for the correlation id above.\n");
    exit(1);
}

/**
 * Explicit local image only: extension must declare JPEG/PNG (never
 * guessed from content, never fetched), size-validated by AIImage.
 */
function manualImageFromFile(string $path): AIImage
{
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, "Image not readable: {$path}\n");
        exit(1);
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        default => null,
    };

    if ($mime === null) {
        fwrite(STDERR, "Unsupported image extension .{$extension} — use .jpg/.jpeg/.png (no silent conversion).\n");
        exit(1);
    }

    $bytes = (string) file_get_contents($path);

    return AIImage::fromRawBytes($mime, $bytes);
}
