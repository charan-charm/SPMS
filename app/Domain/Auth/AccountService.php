<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Account\Account;
use App\Repositories\AccountRepository;
use App\Repositories\AccountTokenRepository;
use App\Repositories\SecurityEventRepository;
use App\Services\Mail\Mailer;
use App\Services\Mail\RecoveryEmailTemplate;
use App\Services\Mail\VerificationEmailTemplate;
use App\Support\AppException;
use App\Support\Config;
use App\Support\DataEnvironments;
use App\Support\Logger;
use App\Support\Security\TokenHasher;
use DateTimeImmutable;

/**
 * W1 — Authentication use-cases (docs/02 §W1, docs/06 §22, docs/10 D-049–D-054).
 *
 * Registration, email verification, login, logout, recovery and reset.
 * Uniform failure semantics: an unknown email, a wrong password and a
 * non-authenticatable account state all produce the identical
 * INVALID_CREDENTIALS response — no information about account existence
 * or the specific failure reason crosses the API boundary. Recovery
 * requests always return the same accepted response whether or not the
 * account exists (docs/08 §7).
 *
 * Email verification uses a 6-digit numeric code (CSPRNG via
 * TokenHasher::generateSixDigit()). The code itself is delivered only
 * inside the email — it is NEVER stored in plaintext, NEVER logged,
 * NEVER returned in any response, and NEVER appears in any URL. Only its
 * SHA-256 hash is persisted. Resend revokes all prior codes before
 * issuing a new one. Excessive wrong-code attempts trigger a rate lock
 * (max VERIFICATION_MAX_ATTEMPTS within VERIFICATION_WINDOW_SECONDS).
 */
final class AccountService
{
    public const VERIFICATION_TTL_HOURS = 24;
    public const RECOVERY_TTL_MINUTES = 60;

    /** Maximum wrong-code attempts before the account's verification is rate-locked. */
    public const VERIFICATION_MAX_ATTEMPTS = 5;

    /** Window in seconds for counting VERIFICATION_FAILED events. */
    public const VERIFICATION_WINDOW_SECONDS = 86400; // 24 hours (matches TTL)

    private readonly ?Logger $logger;

    public function __construct(
        private readonly AccountRepository $accounts = new AccountRepository(),
        private readonly AccountTokenRepository $tokens = new AccountTokenRepository(),
        private readonly SessionService $sessionsService = new SessionService(),
        private readonly SecurityEventRepository $events = new SecurityEventRepository(),
        private readonly RateLimiter $limiter = new RateLimiter(),
        private readonly Mailer $mailer = new Mailer(),
        ?Logger $logger = null
    ) {
        $this->logger = $logger ?? Logger::default();
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

        $isDemo = \App\Support\Database::currentProfile() === DataEnvironments::DEMO;
        $mode = $isDemo ? Account::MODE_DEMO_TEST : Account::MODE_REAL;

        $account = Account::register(
            $email,
            PasswordPolicy::hash($password),
            new DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $mode
        );
        $this->accounts->insert($account);

        $sent = $this->issueVerificationCodeAndSendMail($account);

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
     * Consume an EMAIL_VERIFICATION 6-digit code and activate the account.
     *
     * The $rawCode must be exactly 6 decimal digits (validated here and by
     * the controller). We look up its SHA-256 hash; the plaintext code is
     * never stored, never logged, never echoed in error messages.
     */
    public function verifyEmail(string $rawCode, ?string $rawEmail = null): Account
    {
        // Basic format guard — exactly 6 decimal digits
        if (!is_string($rawCode) || !preg_match('/^\d{6}$/', $rawCode)) {
            throw $this->verificationFailed('verification code must be exactly 6 digits.', null);
        }

        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s');
        $hash = TokenHasher::hash($rawCode);

        // Look up token by hash to determine state (valid, used, or expired)
        $anyToken = $this->tokens->findByHash(
            AccountTokenRepository::TYPE_EMAIL_VERIFICATION,
            $hash
        );

        if ($anyToken === null) {
            // Record failed attempt. Account ID unknown at this point (code didn't match).
            throw $this->verificationFailed('verification code is invalid or expired.', null);
        }

        $accountId = (string) $anyToken['account_id'];

        // If email hint was provided, ensure the token belongs to the matching account
        if ($rawEmail !== null && trim($rawEmail) !== '') {
            $expectedAccount = $this->accounts->findByEmail(Account::normalizeEmail($rawEmail));
            if ($expectedAccount === null || $expectedAccount->id() !== $accountId) {
                throw $this->verificationFailed('verification code is invalid or expired.', null);
            }
        }

        // Per-account attempt limit: prevent brute-forcing the 6-digit space
        $recentFailures = $this->events->countRecentForAccount(
            SecurityEventRepository::VERIFICATION_FAILED,
            $accountId,
            self::VERIFICATION_WINDOW_SECONDS
        );

        if ($recentFailures >= self::VERIFICATION_MAX_ATTEMPTS) {
            throw new AppException(
                'RATE_LIMITED',
                'Too many failed verification attempts. Please request a new code.',
                429,
                ['field' => 'code']
            );
        }

        if ($anyToken['used_at'] !== null) {
            throw $this->verificationFailed('verification code has already been used.', $accountId);
        }

        if ($anyToken['expires_at'] <= $nowStr) {
            throw $this->verificationFailed('verification code has expired.', $accountId);
        }

        if (!$this->tokens->markUsed((string) $anyToken['id'], $nowStr)) {
            throw $this->verificationFailed('verification code has already been used.', $accountId);
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw $this->verificationFailed('account no longer exists.', $accountId);
        }

        $activated = $account->withStatus(
            Account::STATUS_ACTIVE,
            $nowStr,
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
     * actually receive mail. Resend revokes all prior unused codes before
     * issuing a new one — at any moment, at most one valid code exists.
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

            $sent = $this->issueVerificationCodeAndSendMail($account);
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

        $accountId = isset($session['account_id']) && is_string($session['account_id']) && trim($session['account_id']) !== ''
            ? trim($session['account_id'])
            : null;

        if ($accountId !== null) {
            $this->events->record(
                SecurityEventRepository::LOGOUT_SUCCEEDED,
                null,
                $accountId,
                null,
                null
            );
        }
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

        // 6-digit cryptographic recovery code with unified light SPMS email
        $this->issueRecoveryCodeAndSendMail($account);

        return ['accepted' => true];
    }

    /**
     * Verify a 6-digit recovery code without consuming it (Step 2 of recovery).
     *
     * Validates that the code is exactly 6 digits, matches an unconsumed,
     * unexpired PASSWORD_RECOVERY token for the specified account, and
     * enforces per-account attempt bounding.
     *
     * @return array{verified: true, account_id: string}
     */
    public function verifyRecoveryCode(string $rawCode, ?string $rawEmail, ?string $ipAddress): array
    {
        $code = trim($rawCode);
        if (!preg_match('/^[0-9]{6}$/', $code)) {
            throw $this->resetFailed('recovery code must be exactly 6 digits.', $ipAddress);
        }

        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s');
        $hash = TokenHasher::hash($code);

        $accountForEmail = null;
        if ($rawEmail !== null && trim($rawEmail) !== '') {
            $accountForEmail = $this->accounts->findByEmail(Account::normalizeEmail($rawEmail));
        }

        // Check recent failures on the specified email account if known
        if ($accountForEmail !== null) {
            $recentFailures = $this->events->countRecentForAccount(
                SecurityEventRepository::PASSWORD_RESET_FAILED,
                $accountForEmail->id(),
                self::VERIFICATION_WINDOW_SECONDS
            );

            if ($recentFailures >= self::VERIFICATION_MAX_ATTEMPTS) {
                throw new AppException(
                    'RATE_LIMITED',
                    'Too many failed recovery attempts. Please request a new code.',
                    429,
                    ['field' => 'code']
                );
            }
        }

        $anyToken = $this->tokens->findByHash(
            AccountTokenRepository::TYPE_PASSWORD_RECOVERY,
            $hash
        );

        if ($anyToken === null) {
            throw $this->resetFailed('recovery code is invalid or expired.', $ipAddress, $accountForEmail?->id());
        }

        $accountId = (string) $anyToken['account_id'];

        if ($accountForEmail !== null && $accountForEmail->id() !== $accountId) {
            throw $this->resetFailed('recovery code is invalid or expired.', $ipAddress, $accountForEmail->id());
        }

        // Per-account attempt limit: max attempts before rate-lock
        $recentFailures = $this->events->countRecentForAccount(
            SecurityEventRepository::PASSWORD_RESET_FAILED,
            $accountId,
            self::VERIFICATION_WINDOW_SECONDS
        );

        if ($recentFailures >= self::VERIFICATION_MAX_ATTEMPTS) {
            throw new AppException(
                'RATE_LIMITED',
                'Too many failed recovery attempts. Please request a new code.',
                429,
                ['field' => 'code']
            );
        }

        if ($anyToken['used_at'] !== null) {
            throw $this->resetFailed('recovery code has already been used.', $ipAddress, $accountId);
        }

        if ($anyToken['expires_at'] <= $nowStr) {
            throw $this->resetFailed('recovery code has expired.', $ipAddress, $accountId);
        }

        return ['verified' => true, 'account_id' => $accountId];
    }

    /**
     * Consume a PASSWORD_RECOVERY 6-digit code, set the new password and revoke
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

        if (!is_string($rawToken) || trim($rawToken) === '') {
            throw $this->resetFailed('recovery code is required.', $ipAddress);
        }

        $code = trim($rawToken);
        $password = PasswordPolicy::validate($rawPassword);

        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s');
        $hash = TokenHasher::hash($code);

        $anyToken = $this->tokens->findByHash(
            AccountTokenRepository::TYPE_PASSWORD_RECOVERY,
            $hash
        );

        if ($anyToken === null) {
            throw $this->resetFailed('recovery code is invalid or expired.', $ipAddress);
        }

        $accountId = (string) $anyToken['account_id'];

        $recentFailures = $this->events->countRecentForAccount(
            SecurityEventRepository::PASSWORD_RESET_FAILED,
            $accountId,
            self::VERIFICATION_WINDOW_SECONDS
        );

        if ($recentFailures >= self::VERIFICATION_MAX_ATTEMPTS) {
            throw new AppException(
                'RATE_LIMITED',
                'Too many failed recovery attempts. Please request a new code.',
                429,
                ['field' => 'code']
            );
        }

        if ($anyToken['used_at'] !== null) {
            throw $this->resetFailed('recovery code has already been used.', $ipAddress, $accountId);
        }

        if ($anyToken['expires_at'] <= $nowStr) {
            throw $this->resetFailed('recovery code has expired.', $ipAddress, $accountId);
        }

        if (!$this->tokens->markUsed((string) $anyToken['id'], $nowStr)) {
            throw $this->resetFailed('recovery code has already been used.', $ipAddress, $accountId);
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw $this->resetFailed('account no longer exists.', $ipAddress, $accountId);
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

    // ─── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Issue a 6-digit verification code, revoke any prior unused codes,
     * and deliver the SPMS-branded HTML email via the Mailer.
     *
     * The raw code exists only in memory for the duration of this call and
     * inside the delivered email. It is NEVER logged or persisted in plaintext.
     * Only its SHA-256 hash is stored in account_tokens.
     */
    private function issueVerificationCodeAndSendMail(Account $account): bool
    {
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s');

        // Resend invalidation: revoke all prior unused codes before issuing a new one.
        $this->tokens->revokeUnusedByAccountAndType(
            $account->id(),
            AccountTokenRepository::TYPE_EMAIL_VERIFICATION,
            $nowStr
        );

        $attempts = 0;
        while (true) {
            $rawCode = TokenHasher::generateSixDigit();
            try {
                $this->tokens->create(
                    $account->id(),
                    AccountTokenRepository::TYPE_EMAIL_VERIFICATION,
                    TokenHasher::hash($rawCode),
                    $now->modify('+' . self::VERIFICATION_TTL_HOURS . ' hours')->format('Y-m-d H:i:s'),
                    $nowStr
                );
                break;
            } catch (\PDOException $e) {
                if (++$attempts >= 5 || !str_contains($e->getMessage(), 'Duplicate entry')) {
                    throw $e;
                }
            }
        }

        try {
            $htmlBody = VerificationEmailTemplate::buildHtml($rawCode, self::VERIFICATION_TTL_HOURS);
            $textBody = VerificationEmailTemplate::buildText($rawCode, self::VERIFICATION_TTL_HOURS);

            $this->mailer->send(
                $account->email(),
                'SPMS — Verify your email address',
                $textBody,
                $htmlBody
            );

            $this->logger?->info('mail.dispatch_succeeded', [
                'component' => 'Mail',
                'operation' => 'verification email',
                'token_type' => AccountTokenRepository::TYPE_EMAIL_VERIFICATION,
                'recipient' => $account->email(),
                // Code is intentionally NOT logged — never log verification codes.
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('mail.dispatch_failed', [
                'component' => 'Mail',
                'operation' => 'verification email',
                'token_type' => AccountTokenRepository::TYPE_EMAIL_VERIFICATION,
                'recipient' => $account->email(),
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            $this->events->record(
                SecurityEventRepository::MAIL_SEND_FAILED,
                null,
                $account->id(),
                ['token_type' => AccountTokenRepository::TYPE_EMAIL_VERIFICATION],
                null
            );

            return false;
        }
    }

    /**
     * Issue a 6-digit recovery code, revoke any prior unused recovery codes,
     * and deliver the SPMS-branded HTML email via the Mailer.
     * Returns whether delivery succeeded.
     */
    private function issueRecoveryCodeAndSendMail(Account $account): bool
    {
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s');

        // Revoke any prior unused recovery codes for this account (resend invalidation)
        $this->tokens->revokeUnusedByAccountAndType(
            $account->id(),
            AccountTokenRepository::TYPE_PASSWORD_RECOVERY,
            $nowStr
        );

        $attempts = 0;
        while (true) {
            $rawCode = TokenHasher::generateSixDigit();
            try {
                $this->tokens->create(
                    $account->id(),
                    AccountTokenRepository::TYPE_PASSWORD_RECOVERY,
                    TokenHasher::hash($rawCode),
                    $now->modify('+' . self::RECOVERY_TTL_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
                    $nowStr
                );
                break;
            } catch (\PDOException $e) {
                if (++$attempts >= 5 || !str_contains($e->getMessage(), 'Duplicate entry')) {
                    throw $e;
                }
            }
        }

        try {
            $htmlBody = RecoveryEmailTemplate::buildHtml($rawCode, self::RECOVERY_TTL_MINUTES);
            $textBody = RecoveryEmailTemplate::buildText($rawCode, self::RECOVERY_TTL_MINUTES);

            $this->mailer->send(
                $account->email(),
                'SPMS — Reset your password',
                $textBody,
                $htmlBody
            );

            $this->logger?->info('mail.dispatch_succeeded', [
                'component' => 'Mail',
                'operation' => 'recovery email',
                'token_type' => AccountTokenRepository::TYPE_PASSWORD_RECOVERY,
                'recipient' => $account->email(),
                // Code is intentionally NOT logged — never log recovery codes.
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('mail.dispatch_failed', [
                'component' => 'Mail',
                'operation' => 'recovery email',
                'token_type' => AccountTokenRepository::TYPE_PASSWORD_RECOVERY,
                'recipient' => $account->email(),
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            $this->events->record(
                SecurityEventRepository::MAIL_SEND_FAILED,
                null,
                $account->id(),
                ['token_type' => AccountTokenRepository::TYPE_PASSWORD_RECOVERY],
                null
            );

            return false;
        }
    }

    /**
     * Issue a token of the given type and deliver it through the mail
     * abstraction (plain-text only — for password recovery long tokens).
     * Returns whether delivery succeeded.
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

            $this->logger?->info('mail.dispatch_succeeded', [
                'component' => 'Mail',
                'operation' => 'recovery email',
                'token_type' => $type,
                'recipient' => $account->email(),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('mail.dispatch_failed', [
                'component' => 'Mail',
                'operation' => 'recovery email',
                'token_type' => $type,
                'recipient' => $account->email(),
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

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
            // Deliberately omit the message from the event to prevent
            // code enumeration information leaking into the audit log
            null,
            null
        );

        return new AppException('VALIDATION_ERROR', $message, 422, ['field' => 'code']);
    }

    private function resetFailed(string $message, ?string $ipAddress, ?string $accountId = null): AppException
    {
        $this->events->record(
            SecurityEventRepository::PASSWORD_RESET_FAILED,
            RateLimiter::ipBucket($ipAddress),
            $accountId,
            ['message' => $message],
            $ipAddress
        );

        return new AppException('VALIDATION_ERROR', $message, 422, ['field' => 'token']);
    }
}
