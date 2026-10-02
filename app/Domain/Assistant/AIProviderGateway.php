<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Assistant\Providers\AIProviderInterface;
use App\Domain\Assistant\Providers\AssistantProviderException;
use App\Domain\Assistant\Providers\AssistantProviderResponse;
use App\Domain\Assistant\Providers\GeminiProvider;
use App\Domain\Assistant\Providers\GroqProvider;
use App\Domain\Assistant\Providers\OpenRouterProvider;
use App\Support\Config;
use Throwable;

/**
 * Gateway managing AI provider invocation and sequential fallback (Gemini -> Groq -> OpenRouter).
 */
final class AIProviderGateway
{
    /** @var array<string, AIProviderInterface> */
    private array $providers = [];

    private string $primaryProviderName;
    private bool $fallbackEnabled;

    /**
     * @param array<string, AIProviderInterface>|null $customProviders Optional injected providers (e.g. for testing)
     * @param string|null $primaryProvider
     * @param bool|null $fallbackEnabled
     */
    public function __construct(
        ?array $customProviders = null,
        ?string $primaryProvider = null,
        ?bool $fallbackEnabled = null
    ) {
        if ($customProviders !== null) {
            $this->providers = $customProviders;
        } else {
            $this->providers = [
                'gemini' => new GeminiProvider(),
                'groq' => new GroqProvider(),
                'openrouter' => new OpenRouterProvider(),
            ];
        }

        $configuredPrimary = $primaryProvider ?? Config::get('AI_PRIMARY_PROVIDER', 'gemini');
        $this->primaryProviderName = strtolower(trim((string) $configuredPrimary));

        $fb = $fallbackEnabled ?? (Config::get('AI_FALLBACK_ENABLED', 'true') === 'true');
        $this->fallbackEnabled = (bool) $fb;
    }

    /**
     * Generate completion with automatic sequential fallback.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return array{
     *     response: AssistantProviderResponse,
     *     fallback_used: bool,
     *     attempts: list<array{provider: string, status: string, error: ?string}>
     * }
     */
    public function complete(array $messages, array $options = []): array
    {
        // 1. Establish sequence: primary first, followed by remaining configured providers in canonical order
        $fallbackChain = ['gemini', 'groq', 'openrouter'];
        $sequence = [];

        // Put primary first if available
        if (isset($this->providers[$this->primaryProviderName])) {
            $sequence[] = $this->primaryProviderName;
        }

        // Append fallback providers in order if fallback is enabled
        if ($this->fallbackEnabled) {
            foreach ($fallbackChain as $pName) {
                if (!in_array($pName, $sequence, true) && isset($this->providers[$pName])) {
                    $sequence[] = $pName;
                }
            }

            // Also check any custom providers not in fallback chain (e.g. 'fake')
            foreach (array_keys($this->providers) as $pName) {
                if (!in_array($pName, $sequence, true)) {
                    $sequence[] = $pName;
                }
            }
        }

        if ($sequence === []) {
            throw new AssistantProviderException(
                'gateway',
                AssistantProviderException::ERROR_NOT_CONFIGURED,
                'No AI providers are registered in the gateway.'
            );
        }

        $attempts = [];
        $lastException = null;

        foreach ($sequence as $index => $providerName) {
            $provider = $this->providers[$providerName];

            // If provider lacks credentials, record skipped attempt and continue
            if (!$provider->isConfigured()) {
                $attempts[] = [
                    'provider' => $providerName,
                    'status' => 'SKIPPED',
                    'error' => 'Provider not configured (missing API key).',
                ];
                continue;
            }

            try {
                $response = $provider->generate($messages, $options);

                $fallbackUsed = $index > 0;
                $attempts[] = [
                    'provider' => $providerName,
                    'status' => 'SUCCESS',
                    'error' => null,
                ];

                return [
                    'response' => $response,
                    'fallback_used' => $fallbackUsed,
                    'attempts' => $attempts,
                ];
            } catch (AssistantProviderException $e) {
                $lastException = $e;
                $attempts[] = [
                    'provider' => $providerName,
                    'status' => 'FAILED',
                    'error' => $e->getMessage(),
                ];

                // If fallback is disabled, don't try next provider
                if (!$this->fallbackEnabled) {
                    break;
                }
            } catch (Throwable $e) {
                $lastException = new AssistantProviderException(
                    $providerName,
                    AssistantProviderException::ERROR_PROVIDER_UNAVAILABLE,
                    'Unexpected provider exception: ' . $e->getMessage(),
                    500,
                    $e
                );
                $attempts[] = [
                    'provider' => $providerName,
                    'status' => 'FAILED',
                    'error' => $e->getMessage(),
                ];

                if (!$this->fallbackEnabled) {
                    break;
                }
            }
        }

        throw new AssistantProviderException(
            'gateway',
            AssistantProviderException::ERROR_ALL_PROVIDERS_UNAVAILABLE,
            'All configured AI providers failed. Attempts: ' . json_encode($attempts, JSON_UNESCAPED_SLASHES),
            503,
            $lastException
        );
    }
}
