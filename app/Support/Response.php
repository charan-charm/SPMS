<?php

declare(strict_types=1);

namespace App\Support;

final class Response
{
    private int $status;
    private array $headers;

    private function __construct(
        private readonly bool $success,
        private readonly ?array $data,
        private readonly ?array $error,
        private array $meta,
        int $status
    ) {
        $this->status = $status;
        $this->headers = [];
    }

    public static function success(?array $data = null, int $status = 200, array $meta = []): self
    {
        return new self(true, $data, null, $meta, $status);
    }

    public static function error(string $code, string $message, int $status, array $context = [], array $meta = []): self
    {
        $error = ['code' => $code, 'message' => $message];

        if ($context !== []) {
            $error['context'] = $context;
        }

        return new self(false, null, $error, $meta, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function data(): ?array
    {
        return $this->data;
    }

    public function body(): array
    {
        return [
            'success' => $this->success,
            'data' => $this->data,
            'error' => $this->error,
            'meta' => $this->meta,
        ];
    }

    public function withMeta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);
        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** Test/integration access to constructed headers (e.g. Set-Cookie). */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        $meta = $this->meta;

        $requestId = RequestId::current();
        if ($requestId !== null) {
            $meta['request_id'] = $meta['request_id'] ?? $requestId;
            $this->headers['X-Request-Id'] = $this->headers['X-Request-Id'] ?? $requestId;
        }

        if (ob_get_level() > 0 && ob_get_length() > 0) {
            $discarded = ob_get_contents();
            if ($discarded !== false && $discarded !== '') {
                try {
                    Logger::default()->warning('response.output_buffer_cleared', [
                        'discarded_output' => $discarded,
                    ]);
                } catch (\Throwable) {
                    // Fail-safe: do not block response if logging fails
                }
            }
            ob_clean();
        }

        if (PHP_SAPI !== 'cli') {
            http_response_code($this->status);

            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                foreach ($this->headers as $name => $value) {
                    header($name . ': ' . $value);
                }
            }
        }

        $payload = $this->body();
        $payload['meta'] = $meta;

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
