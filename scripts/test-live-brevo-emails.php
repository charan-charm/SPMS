<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\Mail\Mailer;
use App\Services\Mail\RecoveryEmailTemplate;
use App\Services\Mail\VerificationEmailTemplate;
use App\Support\Config;

Config::initialize(dirname(__DIR__));
Config::overrideForTesting([
    'MAIL_TRANSPORT' => 'brevo',
    'BREVO_ALLOW_REAL_SEND' => '1',
]);

$recipient = 'spms.solarproject@gmail.com';
$mailer = new Mailer();

echo "=== Controlled SPMS Brevo Live Email Test ===" . PHP_EOL;
echo "Recipient: " . $recipient . PHP_EOL;

// 1. Send exactly ONE Verification Email
$verifyCode = sprintf('%06d', random_int(100000, 999999));
echo PHP_EOL . "1. Sending Verification Email (Code: " . $verifyCode . ")..." . PHP_EOL;
$verifySubject = 'SPMS — Verify your email address';
$verifyHtml = VerificationEmailTemplate::buildHtml($verifyCode, 24);
$verifyText = VerificationEmailTemplate::buildText($verifyCode, 24);

try {
    $mailer->send($recipient, $verifySubject, $verifyText, $verifyHtml);
    echo "   [SUCCESS] Verification email accepted by Brevo (HTTP 201)." . PHP_EOL;
} catch (\Throwable $e) {
    echo "   [FAILED] " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// 2. Send exactly ONE Password Recovery Email
$recoveryCode = sprintf('%06d', random_int(100000, 999999));
echo PHP_EOL . "2. Sending Password Recovery Email (Code: " . $recoveryCode . ")..." . PHP_EOL;
$recoverySubject = 'SPMS — Reset your password';
$recoveryHtml = RecoveryEmailTemplate::buildHtml($recoveryCode, 60);
$recoveryText = RecoveryEmailTemplate::buildText($recoveryCode, 60);

try {
    $mailer->send($recipient, $recoverySubject, $recoveryText, $recoveryHtml);
    echo "   [SUCCESS] Password recovery email accepted by Brevo (HTTP 201)." . PHP_EOL;
} catch (\Throwable $e) {
    echo "   [FAILED] " . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo PHP_EOL . "=== Live Email Test Completed Successfully ===" . PHP_EOL;
