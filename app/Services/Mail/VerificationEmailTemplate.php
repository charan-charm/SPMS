<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * SPMS-branded HTML verification email — Light theme with approved PNG logo and botanical framing.
 *
 * Delegates to the unified SpmsEmailLayout to maintain identical visual branding
 * across all transactional emails.
 */
final class VerificationEmailTemplate
{
    /**
     * Build the light-themed HTML body for the verification email.
     *
     * @param string $code         The 6-digit verification code (raw, not hashed)
     * @param int    $expiryHours  Hours until the code expires (default 24)
     * @return string              Inline-styled HTML safe for transactional email
     */
    public static function buildHtml(string $code, int $expiryHours = 24): string
    {
        $expiryLabel = $expiryHours === 1 ? '1 hour' : $expiryHours . ' hours';

        return SpmsEmailLayout::renderHtml([
            'subject' => 'SPMS — Verify your email address',
            'title' => 'Verify your email address',
            'subtitle' => 'Use the verification code below to activate your SPMS account.',
            'code' => $code,
            'expiryText' => "This code expires in {$expiryLabel}.",
            'instruction' => 'Enter this code on the SPMS verification page.',
            'securityNote' => 'If you did not create this account, you can safely ignore this email.',
        ]);
    }

    /**
     * Plain-text fallback for email clients that do not render HTML.
     */
    public static function buildText(string $code, int $expiryHours = 24): string
    {
        $expiryLabel = $expiryHours === 1 ? '1 hour' : $expiryHours . ' hours';

        return SpmsEmailLayout::renderText([
            'title' => 'Verify your email address',
            'subtitle' => 'Use the verification code below to activate your SPMS account:',
            'code' => $code,
            'expiryText' => "This code expires in {$expiryLabel}.",
            'instruction' => 'Enter this code on the SPMS verification page.',
            'securityNote' => 'If you did not create this account, you can safely ignore this email.',
        ]);
    }
}
