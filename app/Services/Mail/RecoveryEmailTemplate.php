<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * SPMS-branded HTML password recovery email — Light theme with approved PNG logo and botanical framing.
 *
 * Delegates to the unified SpmsEmailLayout to maintain identical visual branding
 * across all transactional emails.
 */
final class RecoveryEmailTemplate
{
    /**
     * Build the light-themed HTML body for the password recovery email.
     *
     * @param string $code           The 6-digit recovery code (raw, not hashed)
     * @param int    $expiryMinutes  Minutes until the code expires (default 60)
     * @return string                Inline-styled HTML safe for transactional email
     */
    public static function buildHtml(string $code, int $expiryMinutes = 60): string
    {
        $expiryLabel = $expiryMinutes === 1 ? '1 minute' : $expiryMinutes . ' minutes';

        return SpmsEmailLayout::renderHtml([
            'subject' => 'SPMS — Reset your password',
            'title' => 'Reset your password',
            'subtitle' => 'Use the recovery code below to securely reset your SPMS password.',
            'code' => $code,
            'expiryText' => "This code expires in {$expiryLabel}.",
            'instruction' => 'Enter this code on the SPMS password recovery page.',
            'securityNote' => 'If you did not request a password reset, no action is required.',
        ]);
    }

    /**
     * Plain-text fallback for email clients that do not render HTML.
     */
    public static function buildText(string $code, int $expiryMinutes = 60): string
    {
        $expiryLabel = $expiryMinutes === 1 ? '1 minute' : $expiryMinutes . ' minutes';

        return SpmsEmailLayout::renderText([
            'title' => 'Reset your password',
            'subtitle' => 'Use the recovery code below to securely reset your SPMS password:',
            'code' => $code,
            'expiryText' => "This code expires in {$expiryLabel}.",
            'instruction' => 'Enter this code on the SPMS password recovery page.',
            'securityNote' => 'If you did not request a password reset, no action is required.',
        ]);
    }
}
