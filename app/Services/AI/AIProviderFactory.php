<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Support\Config;

/**
 * AI foundation (docs/10 D-066) — provider selection (mirrors the
 * ratified Mailer pattern from D-054/D-065 lineage).
 *
 * AI_PROVIDER selects the implementation: "fake" (the default —
 * deterministic, offline) or "bedrock". Even when "bedrock" is
 * selected, real network calls additionally require the explicit
 * BEDROCK_ALLOW_REAL_CALLS=1 guard flag; the automated test suite pins
 * both away (tests/run-tests.php). Credentials are resolved by the AWS
 * SDK's standard chain — this factory accepts none, forwards none, and
 * nothing here may ever receive credentials from a request payload.
 */
final class AIProviderFactory
{
    private readonly AIProviderInterface $provider;

    public function __construct(?AIProviderInterface $provider = null)
    {
        if ($provider !== null) {
            $this->provider = $provider;

            return;
        }

        $selected = Config::get('AI_PROVIDER', 'fake') ?? 'fake';

        $this->provider = match ($selected) {
            'fake' => new FakeAIProvider(),
            'bedrock' => new BedrockAIProvider(),
            default => throw AIException::configuration('Unknown AI provider: ' . $selected),
        };
    }

    public function provider(): AIProviderInterface
    {
        return $this->provider;
    }

    public function complete(AIRequest $request): AIResponse
    {
        return $this->provider->complete($request);
    }
}
