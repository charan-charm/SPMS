<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RequestId;
use App\Support\Response;
use Tests\Support\Assert;
use Tests\TestResult;

final class ResponseEnvelopeTest
{
    public static function run(): array
    {
        return [
            TestResult::check('Success envelope has success/data/error/meta structure', function (): void {
                $response = Response::success(['value' => 42], 200, ['page' => 1]);

                Assert::same(200, $response->status());
                Assert::same(
                    ['success' => true, 'data' => ['value' => 42], 'error' => null, 'meta' => ['page' => 1]],
                    $response->body()
                );
            }),

            TestResult::check('Error envelope carries stable code, safe message, context', function (): void {
                $response = Response::error('NOT_FOUND', 'Resource missing', 404, ['path' => '/api/x']);

                Assert::same(404, $response->status());
                $body = $response->body();
                Assert::same(false, $body['success']);
                Assert::same(null, $body['data']);
                Assert::same('NOT_FOUND', $body['error']['code']);
                Assert::same('Resource missing', $body['error']['message']);
                Assert::same('/api/x', $body['error']['context']['path']);
            }),

            TestResult::check('Envelope meta includes correlation request_id and valid JSON encoding', function (): void {
                $requestId = RequestId::new();
                RequestId::set($requestId);

                ob_start();
                Response::success(['ok' => true])->send();
                $raw = (string) ob_get_clean();

                $body = Assert::jsonDecodes($raw);
                Assert::same(true, $body['success']);
                Assert::same($requestId, $body['meta']['request_id']);
            }),
        ];
    }
}
