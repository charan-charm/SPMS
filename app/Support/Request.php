<?php

declare(strict_types=1);

namespace App\Support;

use App\Security\AuthContext;

final class Request
{
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly ?string $ipAddress = null,
        private readonly ?AuthContext $authContext = null,
        private readonly array $files = []
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $path = '/';
        if (isset($_SERVER['REQUEST_URI'])) {
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
            $path = '/' . ltrim($path, '/');
        }

        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
            $path = $path === '' ? '/' : $path;
        }

        $query = is_array($_GET) ? $_GET : [];

        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    // Malformed JSON is a client error, never silently
                    // treated as an empty body.
                    throw new AppException(
                        'VALIDATION_ERROR',
                        'Request body must be valid JSON.',
                        400
                    );
                }
                $body = $decoded;
            }
        } elseif (str_contains($contentType, 'multipart/form-data')) {
            // B2 (docs/10 D-068): text parts of a multipart request are
            // exposed to the body like any other form field — endpoints
            // decide (strictly) what may be there at all. Uploaded FILE
            // parts are separate: they come from $_FILES and are never
            // interpreted as body data.
            $body = is_array($_POST) ? $_POST : [];
        }

        // B2: raw $_FILES specs are exposed untouched-but-untrusted; every
        // consumer must re-validate them fail-closed (BillUpload does).
        $files = [];
        foreach ((is_array($_FILES) ? $_FILES : []) as $name => $spec) {
            if (is_array($spec)) {
                $files[(string) $name] = $spec;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }

        $ipAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : null;

        return new self($method, $path, $query, $body, $headers, $ipAddress, null, $files);
    }

    public static function create(string $method, string $path, array $query = [], array $body = [], array $headers = [], ?string $ipAddress = null, array $files = []): self
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }

        return new self(strtoupper($method), $path, $query, $body, $normalized, $ipAddress, null, $files);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function queryParam(string $name): ?string
    {
        $value = $this->query[$name] ?? null;
        return is_scalar($value) ? (string) $value : null;
    }

    public function bodyParam(string $name): mixed
    {
        return $this->body[$name] ?? null;
    }

    public function body(): array
    {
        return $this->body;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function authContext(): ?AuthContext
    {
        return $this->authContext;
    }

    /** Middleware injects the resolved authentication context here. */
    public function withAuthContext(AuthContext $authContext): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->headers,
            $this->ipAddress,
            $authContext,
            $this->files
        );
    }

    /**
     * The raw $_FILES entry for a field name, or null. UNTRUSTED by
     * definition — consumers must validate it fail-closed (B2's
     * BillUpload::fromFilesSpec is that gate; nothing else may open
     * uploads).
     *
     * @return array<string, mixed>|null
     */
    public function uploadedFile(string $field): ?array
    {
        $spec = $this->files[$field] ?? null;

        return is_array($spec) ? $spec : null;
    }
}
