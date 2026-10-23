<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Excel (.xlsx) attachment response for F8 reporting and data exports.
 *
 * Implements the standard SPMS response interface (status, send, withHeader)
 * with OpenXML spreadsheet MIME type, RFC 4180 / HTTP Content-Disposition,
 * and security headers.
 */
final class ExcelResponse
{
    private int $status;

    /** @var array<string, string> */
    private array $headers = [];

    private function __construct(
        private readonly string $binary,
        string $filename,
        int $status = 200
    ) {
        $this->status = $status;
        $this->headers['Content-Type'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $this->headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"';
        $this->headers['X-Content-Type-Options'] = 'nosniff';
        $this->headers['Content-Length'] = (string) strlen($binary);
    }

    public static function create(string $binary, string $filename, int $status = 200): self
    {
        return new self($binary, $filename, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->binary;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        $headers = $this->headers;

        $requestId = RequestId::current();
        if ($requestId !== null) {
            $headers['X-Request-Id'] = $headers['X-Request-Id'] ?? $requestId;
        }

        if (ob_get_level() > 0 && ob_get_length() > 0) {
            ob_clean();
        }

        if (PHP_SAPI !== 'cli') {
            http_response_code($this->status);

            if (!headers_sent()) {
                foreach ($headers as $name => $value) {
                    header($name . ': ' . $value);
                }
            }
        }

        echo $this->binary;
    }
}
