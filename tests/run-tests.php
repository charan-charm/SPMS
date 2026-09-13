<?php

declare(strict_types=1);

/**
 * SPMS M0 verification runner (plain PHP, no framework).
 *
 * Usage:
 *   php tests/run-tests.php            run all suites
 *   php tests/run-tests.php Unit       run one suite (Unit|Integration|API|Security|Workflow)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Config;
use Tests\Support\Assert;
use Tests\TestResult;

$basePath = dirname(__DIR__);
Config::initialize($basePath);

// W1.1 — HARD test isolation: the automated suite must never be able to
// produce a real Brevo API request, regardless of the developer's local
// .env. Every test process pins the file sink and disables real Brevo
// sending (BrevoMailTransport refuses to send without the explicit flag).
Config::overrideForTesting([
    'MAIL_TRANSPORT' => 'file',
    'BREVO_ALLOW_REAL_SEND' => '0',
]);

$suiteFilter = $argv[1] ?? null;

$suites = [
    'Unit' => [
        Tests\Unit\SupportComponentsTest::class,
        Tests\Unit\RouterTest::class,
        Tests\Unit\ResponseEnvelopeTest::class,
        Tests\Unit\EnergyBalanceTest::class,
        Tests\Unit\EnergyCostTest::class,
        Tests\Unit\InsightTest::class,
        Tests\Unit\AccountTest::class,
        Tests\Unit\MailTransportTest::class,
        Tests\Unit\HouseholdTest::class,
    ],
    'Integration' => [
        Tests\Integration\DatabaseConnectionTest::class,
        Tests\Integration\MigrationRunnerTest::class,
        Tests\Integration\AuthMigrationsTest::class,
        Tests\Integration\HouseholdMigrationsTest::class,
    ],
    'API' => [
        Tests\API\HealthApiTest::class,
        Tests\API\EnergyBalanceApiTest::class,
        Tests\API\EnergyCostApiTest::class,
        Tests\API\InsightApiTest::class,
        Tests\API\AuthApiTest::class,
        Tests\API\HouseholdApiTest::class,
    ],
    'Security' => [
        Tests\Security\SafeErrorsTest::class,
        Tests\Security\AuthSecurityTest::class,
        Tests\Security\OwnershipSecurityTest::class,
    ],
    'Workflow' => [
        Tests\Workflow\AuthWorkflowTest::class,
        Tests\Workflow\HouseholdOwnershipWorkflowTest::class,
    ],
];

$allResults = [];
$overall = true;

foreach ($suites as $suiteName => $testClasses) {
    if ($suiteFilter !== null && strcasecmp($suiteFilter, $suiteName) !== 0) {
        continue;
    }

    echo PHP_EOL . "=== Suite: {$suiteName} ===" . PHP_EOL;

    foreach ($testClasses as $testClass) {
        /** @var TestResult[] $results */
        $results = $testClass::run();

        foreach ($results as $result) {
            $status = $result->skipped ? 'SKIP' : ($result->passed ? 'PASS' : 'FAIL');
            $icon = $result->skipped ? '-' : ($result->passed ? '+' : 'x');
            echo "  [{$status}] {$result->name}" . PHP_EOL;
            if (!$result->passed && $result->detail !== '') {
                echo "         {$result->detail}" . PHP_EOL;
            }
            $allResults[] = $result;
            if (!$result->passed) {
                $overall = false;
            }
        }
    }
}

$total = count($allResults);
$failed = count(array_filter($allResults, static fn (TestResult $r): bool => !$r->passed));
$passed = count(array_filter($allResults, static fn (TestResult $r): bool => $r->passed && !$r->skipped));
$skipped = $total - $passed - $failed;

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo "Total: {$total}  Passed: {$passed}  Failed: {$failed}  Skipped: {$skipped}" . PHP_EOL;
echo $overall ? 'ALL CHECKS PASSED' : 'FAILURES DETECTED';
echo PHP_EOL;

exit($overall ? 0 : 1);
