<?php

declare(strict_types=1);

namespace App\Services\AI;

use Throwable;

/**
 * AI foundation (docs/10 D-066) — AWS failure → normalized category.
 *
 * bedrock-runtime surfaces provider failures through the single generic
 * Aws\BedrockRuntime\Exception\BedrockRuntimeException, so classification
 * keys off the AWS error CODE and HTTP STATUS (both read defensively via
 * method_exists — no hard dependency on SDK types in the mapping logic,
 * which also keeps it unit-testable). This mapper NEVER inspects message
 * text for credentials and NEVER returns provider data; the original
 * Throwable is attached to the AIException by the caller for
 * server-side diagnostics only.
 */
final class BedrockErrorMapper
{
    private function __construct()
    {
    }

    public static function category(Throwable $e): string
    {
        $code = self::awsErrorCode($e);
        $status = self::awsStatus($e);
        $connectionError = self::isConnectionError($e);

        // Transport-level failures first: no HTTP response was received.
        if ($connectionError) {
            return AIErrorCategory::TIMEOUT;
        }

        if ($status === 0 && $code === null) {
            return AIErrorCategory::TIMEOUT;
        }

        if ($code !== null) {
            $normalized = strtolower($code);

            if (self::containsAny($normalized, ['throttl', 'toomanyrequests', 'limitexceeded', 'quotaexceeded'])) {
                return AIErrorCategory::THROTTLING;
            }

            if (self::containsAny($normalized, [
                'accessdenied', 'unrecognizedclient', 'expiredtoken', 'invalidaccesskey',
                'signaturedoesnotmatch', 'incompletesignature', 'invalidsignature', 'missingauthentication',
            ])) {
                return AIErrorCategory::AUTHENTICATION;
            }

            if (self::containsAny($normalized, ['validation', 'malformed', 'invalidrequest', 'unprocessable'])) {
                return AIErrorCategory::INVALID_REQUEST;
            }

            if (self::containsAny($normalized, ['modelnotready', 'modeltimeout', 'modelinprogress', 'modelnotfound', 'resourcenotfound'])) {
                return AIErrorCategory::MODEL_UNAVAILABLE;
            }

            if (self::containsAny($normalized, ['internalserver', 'serviceunavailable', 'servicetoken', 'modeloverloaded'])) {
                return AIErrorCategory::PROVIDER_FAILURE;
            }
        }

        if ($status !== null && $status > 0) {
            if ($status === 429) {
                return AIErrorCategory::THROTTLING;
            }
            if ($status === 401 || $status === 403) {
                return AIErrorCategory::AUTHENTICATION;
            }
            if ($status === 400) {
                return AIErrorCategory::INVALID_REQUEST;
            }
            if ($status === 404) {
                return AIErrorCategory::MODEL_UNAVAILABLE;
            }
            if ($status === 408 || $status === 504) {
                return AIErrorCategory::TIMEOUT;
            }
            if ($status >= 500) {
                return AIErrorCategory::PROVIDER_FAILURE;
            }
        }

        return AIErrorCategory::PROVIDER_FAILURE;
    }

    /**
     * Sanitized AWS provider error CODE (e.g. "ValidationException").
     * Codes are machine metadata and safe for diagnostics; anything
     * that does not look like a bare code token is dropped entirely.
     * The provider MESSAGE/BODY is never returned by this API.
     */
    public static function awsErrorCode(Throwable $e): ?string
    {
        $code = null;

        if (method_exists($e, 'getAwsErrorCode')) {
            $raw = $e->getAwsErrorCode();
            $code = is_string($raw) ? $raw : null;
        }

        if ($code === null || preg_match('/^[A-Za-z][A-Za-z0-9_.\-]{0,63}$/', $code) !== 1) {
            return null;
        }

        return $code;
    }

    private static function awsStatus(Throwable $e): ?int
    {
        if (!method_exists($e, 'getStatusCode')) {
            return null;
        }

        $status = $e->getStatusCode();

        return is_int($status) ? $status : null;
    }

    private static function isConnectionError(Throwable $e): bool
    {
        if (method_exists($e, 'isConnectionError')) {
            return (bool) $e->isConnectionError();
        }

        // Guzzle transport exceptions (ConnectException) bubble up here.
        return str_contains(strtolower(get_class($e)), 'connectexception');
    }

    /**
     * @param string[] $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
