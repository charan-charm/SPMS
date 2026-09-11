<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Account\Account;
use App\Domain\Auth\AccountService;
use App\Security\AuthContext;
use App\Support\AppException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Security\SessionCookie;

/**
 * W1 — Authentication API (docs/02 §W1, docs/06 §22, docs/10 D-049–D-054).
 *
 * POST /api/v1/auth/register          create account + verification mail
 * POST /api/v1/auth/verify            activate account with token
 * POST /api/v1/auth/verify/resend     uniform resend (rate-limited)
 * POST /api/v1/auth/login             authenticate + issue session cookie
 * POST /api/v1/auth/logout            revoke session + clear cookie (CSRF)
 * GET  /api/v1/auth/session           safe authenticated identity + CSRF token
 * POST /api/v1/auth/recovery/request  uniform recovery request (rate-limited)
 * POST /api/v1/auth/recovery/confirm  reset password with token (rate-limited)
 *
 * Responses never contain password hashes, raw session tokens, or
 * security metadata. Unknown email / wrong password / unusable account
 * state are indistinguishable (INVALID_CREDENTIALS).
 */
final class AuthController
{
    public function __construct(
        private readonly AccountService $accounts = new AccountService()
    ) {
    }

    public function register(Request $request): Response
    {
        $email = $this->validatedEmail($request->bodyParam('email'));
        $password = $this->validatedPasswordPresent($request->bodyParam('password'));

        $result = $this->accounts->register($email, $password, $request->ipAddress());

        return Response::success([
            'account' => $result['account']->toPublicArray(),
            'verification' => [
                'required' => true,
                'sent' => $result['verification_sent'],
            ],
        ], 201);
    }

    public function verify(Request $request): Response
    {
        $token = $request->bodyParam('token');
        if (!is_string($token) || $token === '') {
            throw new AppException('VALIDATION_ERROR', 'token is required.', 422, ['field' => 'token']);
        }

        $account = $this->accounts->verifyEmail($token);

        return Response::success([
            'account' => $account->toPublicArray(),
        ]);
    }

    public function verifyResend(Request $request): Response
    {
        $email = $this->validatedEmail($request->bodyParam('email'));

        $result = $this->accounts->resendVerification($email, $request->ipAddress());

        return Response::success($result);
    }

    public function login(Request $request): Response
    {
        $email = $this->validatedEmail($request->bodyParam('email'));
        $password = $this->validatedPasswordPresent($request->bodyParam('password'));

        $priorSession = $request->authContext()?->session();

        $result = $this->accounts->login($email, $password, $request->ipAddress(), $priorSession);

        return Response::success([
            'account' => $result['account']->toPublicArray(),
            'session' => [
                'csrf_token' => $result['csrf_token'],
            ],
        ])->withHeader('Set-Cookie', SessionCookie::issueHeaderValue($result['raw_token']));
    }

    public function logout(Request $request): Response
    {
        $context = $this->requireAuthentication($request);

        $session = $context->session();
        if ($session !== null) {
            $this->accounts->logout($session);
        }

        return Response::success(['logged_out' => true])
            ->withHeader('Set-Cookie', SessionCookie::clearHeaderValue());
    }

    public function session(Request $request): Response
    {
        $context = $this->requireAuthentication($request);

        $account = $context->account();
        $session = $context->session();

        if ($account === null || $session === null) {
            throw new AppException('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        return Response::success([
            'authenticated' => true,
            'account' => $account->toPublicArray(),
            'session' => [
                'csrf_token' => (string) $session['csrf_token'],
            ],
        ]);
    }

    public function recoveryRequest(Request $request): Response
    {
        $email = $this->validatedEmail($request->bodyParam('email'));

        $this->accounts->requestPasswordRecovery($email, $request->ipAddress());

        return Response::success(['accepted' => true]);
    }

    public function recoveryConfirm(Request $request): Response
    {
        $token = $request->bodyParam('token');
        if (!is_string($token) || $token === '') {
            throw new AppException('VALIDATION_ERROR', 'token is required.', 422, ['field' => 'token']);
        }

        $result = $this->accounts->resetPassword($token, $request->bodyParam('password'), $request->ipAddress());

        return Response::success([
            'password_reset' => true,
            'account' => $result['account']->toPublicArray(),
        ]);
    }

    private function requireAuthentication(Request $request): AuthContext
    {
        $context = $request->authContext();

        if ($context === null || !$context->isAuthenticated()) {
            throw new AppException('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        return $context;
    }

    private function validatedEmail(mixed $raw): string
    {
        if (!is_string($raw) || $raw === '') {
            throw new AppException('VALIDATION_ERROR', 'email is required.', 422, ['field' => 'email']);
        }

        if (mb_strlen($raw) > 255 || !filter_var(Account::normalizeEmail($raw), FILTER_VALIDATE_EMAIL)) {
            throw new AppException('VALIDATION_ERROR', 'email must be a valid email address.', 422, ['field' => 'email']);
        }

        return $raw;
    }

    private function validatedPasswordPresent(mixed $raw): string
    {
        if (!is_string($raw) || $raw === '') {
            throw new AppException('VALIDATION_ERROR', 'password is required.', 422, ['field' => 'password']);
        }

        return $raw;
    }
}
