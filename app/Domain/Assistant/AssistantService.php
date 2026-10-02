<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Assistant\Providers\AssistantProviderException;
use App\Repositories\AssistantMessageRepository;
use InvalidArgumentException;

/**
 * High-Level Service coordinating the SPMS Energy Assistant pipeline.
 */
final class AssistantService
{
    private SPMSContextBuilder $contextBuilder;
    private AIProviderGateway $gateway;
    private AssistantResponseValidator $validator;
    private AssistantMessageRepository $messageRepository;

    public function __construct(
        ?SPMSContextBuilder $contextBuilder = null,
        ?AIProviderGateway $gateway = null,
        ?AssistantResponseValidator $validator = null,
        ?AssistantMessageRepository $messageRepository = null
    ) {
        $this->contextBuilder = $contextBuilder ?? new SPMSContextBuilder();
        $this->gateway = $gateway ?? new AIProviderGateway();
        $this->validator = $validator ?? new AssistantResponseValidator();
        $this->messageRepository = $messageRepository ?? new AssistantMessageRepository();
    }

    /**
     * Process an assistant query, build authoritative context, call provider gateway, and return safe response.
     *
     * @param string $householdId
     * @param string $accountId
     * @param string $userMessage
     * @param string|null $pageContext
     * @param list<array<string, mixed>> $clientHistory
     * @return array{
     *     answer: string,
     *     provider: string,
     *     model: string,
     *     context_domains: list<string>,
     *     data_nature: string,
     *     confidence: string,
     *     limitations: list<string>,
     *     fallback_used: bool
     * }
     */
    public function ask(
        string $householdId,
        string $accountId,
        string $userMessage,
        ?string $pageContext = null,
        array $clientHistory = []
    ): array {
        $query = trim($userMessage);
        if ($query === '') {
            throw new InvalidArgumentException('Message cannot be empty.');
        }

        if (mb_strlen($query) > 1000) {
            throw new InvalidArgumentException('Message exceeds maximum limit of 1000 characters.');
        }

        // 1. Build minimal, domain-specific authoritative context
        $context = $this->contextBuilder->build($householdId, $query, $pageContext);

        // 2. Build system instruction prompt with authoritative context
        $systemPrompt = AssistantPrompt::buildSystemPrompt($context['context_text']);

        // 3. Assemble chronological conversation history (capped at last 6 messages)
        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt,
        ];

        // Sanitize previous history
        $sanitizedHistory = [];
        $recentHistory = array_slice($clientHistory, -6);
        foreach ($recentHistory as $msg) {
            $role = strtolower((string) ($msg['role'] ?? 'user'));
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $text = trim((string) ($msg['content'] ?? ''));
            if ($text === '') {
                continue;
            }
            $sanitizedHistory[] = [
                'role' => $role,
                'content' => mb_substr($text, 0, 1000),
            ];
        }

        foreach ($sanitizedHistory as $h) {
            $messages[] = $h;
        }

        // Append current user message
        $messages[] = [
            'role' => 'user',
            'content' => $query,
        ];

        // Save incoming user message in repository
        $this->messageRepository->save(
            householdId: $householdId,
            accountId: $accountId,
            role: 'user',
            content: $query
        );

        // 4. Invoke provider gateway with automatic fallback
        $gatewayResult = $this->gateway->complete($messages);
        $providerResponse = $gatewayResult['response'];
        $fallbackUsed = $gatewayResult['fallback_used'];

        // 5. Run response validation & truth guard
        $validation = $this->validator->validate($providerResponse->content, $context['context_text']);
        $finalAnswer = $validation['sanitized_content'];

        // Save assistant answer in repository
        $this->messageRepository->save(
            householdId: $householdId,
            accountId: $accountId,
            role: 'assistant',
            content: $finalAnswer,
            contextDomains: $context['domains'],
            provider: $providerResponse->provider,
            model: $providerResponse->model,
            fallbackUsed: $fallbackUsed
        );

        // 6. Return canonical contract
        return [
            'answer' => $finalAnswer,
            'provider' => $providerResponse->provider,
            'model' => $providerResponse->model,
            'context_domains' => $context['domains'],
            'data_nature' => $context['data_nature'],
            'confidence' => $context['confidence'],
            'limitations' => $context['limitations'],
            'fallback_used' => $fallbackUsed,
        ];
    }

    /**
     * Get recent conversation history for a household.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $householdId, int $limit = 20): array
    {
        return $this->messageRepository->listRecent($householdId, $limit);
    }

    /**
     * Clear all conversation history for a household.
     */
    public function clearHistory(string $householdId): void
    {
        $this->messageRepository->clear($householdId);
    }
}
