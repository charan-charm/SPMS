<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Account\Account;
use App\Repositories\AccountRepository;
use App\Repositories\AccountTokenRepository;
use App\Repositories\SecurityEventRepository;
use App\Services\Mail\Mailer;
use App\Support\AppException;
use App\Support\Security\TokenHasher;
use DateTimeImmutable;

/**
 * W1 — Authentication use-cases (docs/10 D-049–D-054).
 *
 * Registration, email verification, login, logout, recovery and reset.
 * Uniform failure semantics: an unknown email, a wrong password and a
 * non-authenticatable account state all produce the identical
 * INVALID_CREDENTIALS response — no information about account existence
 * or the specific failure reason crosses the API boundary. Recovery
 * requests always return the same accepted response whether or not the
 * account exists (docs/08 §7).
 */
final class AccountService
{
    public const VERIFICATION_TTL_HOURS = 24;
    public const RECOVERY_TTL_MINUTES = 60;

    public function __construct(
        private readonly AccountRepository $accounts = new AccountRepository(),
        private readonly AccountTokenRepository $tokens = new AccountTokenRepository(),
        private readonly SessionService $sessionsService = new SessionService(),
        private readonly SecurityEventRepository $events = new SecurityEventRepository(),
        private readonly RateLimiter $limiter = new RateLimiter(),
        private readonly Mailer $mailer = new Mailer()
    ) {
    }

    /**
     * @return array{account: Account, verification_sent: bool}
     */
    public function register(string $rawEmail, string $rawPassword, ?string $ipAddress): array
    {
        $email = Account::normalizeEmail($rawEmail);
        $emailBucket = RateLimiter::emailBucket($email);
        $ipBucket = RateLimiter::ipBucket($ipAddress);

        $this->limiter->assertAllowed(
            [SecurityEventRepository::REGISTRATION_SUCCEEDED],
            array_filter([$emailBucket, $ipBucket]),
            $ipAddress
        );

        if ($rawEmail === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            throw new AppException('VALIDATION_ERROR', 'email must be a valid email address.', 422, ['field' => 'email']);
        }

        $password = PasswordPolicy::validate($rawPassword);

        if ($this->accounts->findByEmail($email) !== null) {
            throw new AppException('VALIDATION_ERROR', 'email is already registered.', 422, ['field' => 'email']);
        }

        $account = Account::register($email, PasswordPolicy::hash($password), new DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->accounts->insert($account);

        $sent = $this->issueTokenAndSendMail(
            $account,
            AccountTokenRepository::TYPE_EMAIL_VERIFICATION,
            self::VERIFICATION_TTL_HOURS . ' hours',
            'SPMS — Verify your email address',
            'Welcome to SPMS.' . "\r\n\r\n"
            . 'Use the verification code below to activate your account:' . "\r\n\r\n"
            . '{TOKEN}' . "\r\n\r\n"
            . 'If you did not create this account, no action is required.'
        );

        $this->events->record(
            SecurityEventRepository::REGISTRATION_SUCCEEDED,
            $emailBucket,
            $account->id(),
            ['mail_sent' => $sent],
            $ipAddress
        );

        $this->recordIpBucketEvent(SecurityEventRepository::REGISTRATION_SUCCEEDED, $ipBucket, $account->id(), $ipAddress);

        return ['account' => $account, 'verification_sent' => $sent];
    }

    /**
     * Consume an EMAIL_VERIFICATION token and activate the account.
     */
    public function verifyEmail(string $rawToken): Account
    {
        if (!is_string($rawToken) || $rawToken === '') {
            throw $this->verificationFailed('verification token is required.', null);
        }

        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $token = $this->tokens->findValidByHash(
            AccountTokenRepository::TYPE_EMAIL_VERIFICATION,
            TokenHasher::hash($rawToken),
            $now->format('Y-m-d H:i:s')
        );

        if ($token === null || !$this->tokens->markUsed((string) $token['id'], $now->format('Y-m-d H:i:s'))) {
            throw $this->verificationFailed('verification token is invalid or expired.', $token['account_id'] ?? null);
        }

        $account = $this->accounts->findById((string) $token['account_id']);
        if ($account === null) {
            throw $this->verificationFailed('account no longer exists.', $token['account_id'] ?? null);
        }

        $activated = $account->withStatus(
            Account::STATUS_ACTIVE,
            $now->format('Y-m-d H:i:s'),
            $now
        );
        $this->accounts->updateStatus($activated);

        $this->events->record(
            SecurityEventRepository::EMAIL_VERIFICATION_SUCCEEDED,
            null,
            $activated->id(),
            null,
            null
        );

        return $activated;
    }

    /**
     * Uniform resend: always 200-accepted; only real pending accounts
     * actually receive mail.
     *
     * @return array{accepted: true, sent: bool}
     */
    public function resendVerification(string $rawEmail, ?string $ipAddress): array
    {
        $email = Account::normalizeEmail((string) $rawEmail);
        $emailBucket = RateLimiter::emailBucket($email);
        $ipBucket = RateLimiter::ipBucket($ipAddress);

        $this->limiter->assertAllowed(
            [SecurityEventRepository::VERIFICATION_RESEND_REQUESTED],
            array_filter([$emailBucket, $ipBucket]),
            $ipAddress
        );

        $account = $this->accounts->findByEmail($email);

        $sent = false;
        if ($account !== null && $account->status() === Account::STATUS_PENDING_VERIFICATION) {
            $this->events->record(
                SecurityEventRepository::VERIFICATION_RESEND_REQUESTED,
                $emailBucket,
                $account->id(),
                null,
                $ipAddress
            );

            $sent = $this->issueTokenAndSendMail(
                $account,
                AccountTokenRepository::TYPE_EMAIL_VERIFICATION,
                self::VERIFICATION_TTL_HOURS . ' hours',
                'SPMS — Verify your email address',
                'Use the verification code below to activate your account:' . "\r\n\r\n"
                . '{TOKEN}' . "\r\n\r\n"
                . 'If you did not request this, no action is required.'
            );
        } else {
            $this->events->record(
                SecurityEventRepository::VERIFICATION_RESEND_SUPPRESSED,
                $emailBucket,
                null,
                null,
                $ipAddress
            );
        }

        $eventType = ($account !== null && $account->status() === Account::STATUS_PENDING_VERIFICATION)
            ? SecurityEventRepository::VERIFICATION_RESEND_REQUESTED
            : SecurityEventRepository::VERIFICATION_RESEND_SUPPRESSED;
        $this->recordIpBucketEvent($eventType, $ipBucket, $account?->id(), $ipAddress);

        return ['accepted' => true, 'sent' => $sent];
    }

    /**
     * Authenticate and create a fresh server-side session. Any presented
     * prior session is revoked so its identifier cannot be reused
     * (session fixation defense).
     *
     * @param array<string, mixed>|null $priorSession
     * @return array{account: Account, session: array<string, mixed>, raw_token: string, csrf_token: string}
     */
    public function login(string $rawEmail, string $rawPassword, ?string $ipAddress, ?array $priorSession): array
    {
        $email = Account::normalizeEmail($rawEmail);
        $emailBucket = RateLimiter::emailBucket($email);
        $ipBucket = RateLimiter::ipBucket($ipAddress);

        $this->limiter->assertAllowed(
            [SecurityEventRepository::LOGIN_FAILED],
            array_filter([$emailBucket, $ipBucket]),
            $ipAddress
        );

        $account = is_string($rawEmail) ? $this->accounts->findByEmail($email) : null;

        if ($account === null
            || !PasswordPolicy::verify(is_string($rawPassword) ? $rawPassword : '', $account->passwordHash())
            || !$account->canAuthenticate()) {
            $this->recordLoginFailure($emailBucket, $ipBucket, $account, $ipAddress);
            throw new AppException('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
        }

        $session = $this->sessionsService->start($account->id(), $priorSession);

        $this->events->record(
            SecurityEventRepository::LOGIN_SUCCEEDED,
            $emailBucket,
            $account->id(),
            null,
            $ipAddress
        );

        return [
            'account' => $account,
            'session' => $session['session'],
            'raw_token' => $session['raw_token'],
            'csrf_token' => $session['csrf_token'],
        ];
    }

    /**
     * @param array<string, mixed> $session
     */
    public function logout(array $session): void
    {
        $this->sessionsService->revoke($session, 'LOGOUT');

        $this->events->record(
            SecurityEventRepository::LOGOUT_SUCCEEDED,
            null,
            (string) $session['account_id'],
            null,
            null
        );
    }

    /**
     * Uniform recovery request: the response never reveals whether the
     * account exists (docs/08 §7).
     *
     * @return array{accepted: true}
     */
    public function requestPasswordRecovery(string $rawEmail, ?string $ipAddress): array
    {
        $email = Account::normalizeEmail((string) $rawEmail);
        $emailBucket = RateLimiter::emailBucket($email);
        $ipBucket = RateLimiter::ipBucket($ipAddress);

        $this->limiter->assertAllowed(
            [SecurityEventRepository::RECOVERY_REQUESTED, SecurityEventRepository::RECOVERY_REQUEST_SUPPRESSED],
            array_filter([$emailBucket, $ipBucket]),
            $ipAddress
        );

        $account = $this->accounts->findByEmail($email);

        if ($account === null) {
            $this->events->record(
                SecurityEventRepository::RECOVERY_REQUEST_SUPPRESSED,
                $emailBucket,
                null,
                null,
                $ipAddress
            );
            $this->recordIpBucketEvent(SecurityEventRepository::RECOVERY_REQUEST_SUPPRESSED, $ipBucket, null, $ipAddress);

            return ['accepted' => true];
        }

        $this->events->record(
            SecurityEventRepository::RECOVERY_REQUESTED,
            $emailBucket,
            $account->id(),
            null,
            $ipAddress
        );
        $this->recordIpBucketEvent(SecurityEventRepository::RECOVERY_REQUESTED, $ipBucket, $account->id(), $ipAddress);

        $this->issueTokenAndSendMail(
            $account,
            AccountTokenRepository::TYPE_PASSWORD_RECOVERY,
            self::RECOVERY_TTL_MINUTES . ' minutes',
            'SPMS — Reset your password',
            'Use the recovery code below to reset your password:' . "\r\n\r\n"
            . '{TOKEN}' . "\r\n\r\n"
            . 'The code expires in ' . self::RECOVERY_TTL_MINUTES . ' minutes.' . "\r\n\r\n"
            . 'If you did not request a password reset, no action is required.'
        );

        return ['accepted' => true];
    }

    /**
     * Consume a PASSWORD_RECOVERY token, set the new password and revoke
     * every live session of the account.
     *
     * @return array{account: Account}
     */
    public function resetPassword(string $rawToken, mixed $rawPassword, ?string $ipAddress): array
    {
        $ipBucket = RateLimiter::ipBucket($ipAddress);

        $this->limiter->assertAllowed(
            [SecurityEventRepository::PASSWORD_RESET_FAILED],
            array_filter([$ipBucket]),
            $ipAddress
        );

        if (!is_string($rawToken) || $rawToken === '') {
            throw $this->resetFailed('recovery token is required.', $ipAddress);
        }

        $password = PasswordPolicy::validate($rawPassword);

        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $token = $this->tokens->findValidByHash(
            AccountTokenRepository::TYPE_PASSWORD_RECOVERY,
            TokenHasher::hash($rawToken),
            $now->format('Y-m-d H:i:s')
        );

        if ($token === null || !$this->tokens->markUsed((string) $token['id'], $now->format('Y-m-d H:i:s'))) {
            throw $this->resetFailed('recovery token is invalid or expired.', $ipAddress);
        }

        $account = $this->accounts->findById((string) $token['account_id']);
        if ($account === null) {
            throw $this->resetFailed('account no longer exists.', $ipAddress);
        }

        $updated = $account->withPasswordHash(PasswordPolicy::hash($password), $now);
        $this->accounts->updatePasswordHash($updated);

        $this->sessionsService->revokeAllForAccount($account->id(), 'PASSWORD_RESET');

        $this->events->record(
            SecurityEventRepository::PASSWORD_RESET_SUCCEEDED,
            null,
            $account->id(),
            null,
            $ipAddress
        );

        return ['account' => $updated];
    }

    /**
     * Issue a token of the given type and deliver it through the mail
     * abstraction. Returns whether delivery succeeded; delivery failure
     * is recorded as a security event and never leaks the raw token.
     */
    private function issueTokenAndSendMail(Account $account, string $type, string $ttl, string $subject, string $bodyTemplate): bool
    {
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $rawToken = TokenHasher::generate();

        $this->tokens->create(
            $account->id(),
            $type,
            TokenHasher::hash($rawToken),
            $now->modify('+' . $ttl)->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s')
        );

        try {
            $this->mailer->send(
                $account->email(),
                $subject,
                str_replace('{TOKEN}', $rawToken, $bodyTemplate)
            );

            return true;
        } catch (\Throwable) {
            $this->events->record(
                SecurityEventRepository::MAIL_SEND_FAILED,
                null,
                $account->id(),
                ['token_type' => $type],
                null
            );

            return false;
        }
    }

    /**
     * IP-oriented throttle bucket row: pairs with the email-bucket row so
     * the rate limiter can count broader cross-account attempts from one
     * source IP (docs/10 D-053.7).
     */
    private function recordIpBucketEvent(string $eventType, ?string $ipBucket, ?string $accountId, ?string $ipAddress): void
    {
        if ($ipBucket === null) {
            return;
        }

        $this->events->record($eventType, $ipBucket, $accountId, ['bucket' => 'IP'], $ipAddress);
    }

    private function recordLoginFailure(string $emailBucket, ?string $ipBucket, ?Account $account, ?string $ipAddress): void
    {
        $this->events->record(
            SecurityEventRepository::LOGIN_FAILED,
            $emailBucket,
            $account?->id(),
            ['outcome' => $account === null ? 'UNKNOWN_EMAIL' : 'CREDENTIALS_OR_STATE'],
            $ipAddress
        );

        if ($ipBucket !== null && $ipBucket !== $emailBucket) {
            $this->events->record(
                SecurityEventRepository::LOGIN_FAILED,
                $ipBucket,
                null,
                ['outcome' => 'IP_BUCKET'],
                $ipAddress
            );
        }
    }

    private function verificationFailed(string $message, ?string $accountId): AppException
    {
        $this->events->record(
            SecurityEventRepository::VERIFICATION_FAILED,
            null,
            $accountId,
            ['message' => $message],
            null
        );

        return new AppException('VALIDATION_ERROR', $message, 422, ['field' => 'token']);
    }

    private function resetFailed(string $message, ?string $ipAddress): AppException
    {
        $this->events->record(
            SecurityEventRepository::PASSWORD_RESET_FAILED,
            RateLimiter::ipBucket($ipAddress),
            null,
            ['message' => $message],
            $ipAddress
        );

        return new AppException('VALIDATION_ERROR', $message, 422, ['field' => 'token']);
    }
}
