<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Account\Account;
use App\Domain\Auth\SessionService;
use App\Domain\Household\Household;
use App\Domain\Household\HouseholdService;
use App\Repositories\AccountRepository;
use App\Security\Authorization;
use App\Support\AppException;
use App\Support\Database;
use App\Support\DataEnvironments;
use App\Support\Request;
use App\Support\Response;
use App\Support\Security\SessionCookie;
use Database\Seeders\DemoScenarioSeeder;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

require_once dirname(__DIR__, 2) . '/database/seeders/DemoScenarioSeeder.php';

/**
 * SPMS D1 — Secure Scenario Discovery & Switching Controller.
 *
 * REST Surface:
 * - GET  /api/v1/demo/scenarios        Discover eligible demo scenarios and active narrative
 * - POST /api/v1/demo/scenarios/switch Safely switch active scenario context for the canonical DEMO account
 *
 * Security Boundaries:
 * - Strictly restricted to DEMO environment (spms_demo / test). Fails closed (403) in REAL.
 * - Requires authenticated session with DEMO_TEST account mode.
 * - Target scenario validated against strict server-side allowlist (SCEN-01..05).
 * - Switches household context without requiring re-login or credential re-entry.
 * - Rotates and regenerates server-side session; revokes previous session identifier.
 * - Never returns passwords or password hashes.
 */
final class DemoScenarioController
{
    public function __construct(
        private readonly AccountRepository $accounts = new AccountRepository(),
        private readonly HouseholdService $households = new HouseholdService(),
        private readonly SessionService $sessions = new SessionService()
    ) {
    }

    /**
     * GET /api/v1/demo/scenarios
     */
    public function index(Request $request): Response
    {
        $account = Authorization::requireAccount($request);
        $this->assertDemoEnvironment($account);

        $scenarios = [];
        $activeScenario = null;
        $hhContext = $this->households->activeHouseholdFor($account);
        $activeServiceNumber = $hhContext ? str_replace('-', '', (string) $hhContext['household']->serviceNumber()) : null;
        $activeLabel = $hhContext ? $hhContext['household']->label() : null;

        // Default active scenario is SCEN-01
        $activeScenarioId = 'SCEN-01';

        foreach (DemoScenarioSeeder::SCENARIOS as $id => $meta) {
            $cleanMetaSn = str_replace('-', '', (string) $meta['service_number']);
            $matches = false;
            if ($activeServiceNumber !== null && $activeServiceNumber === $cleanMetaSn) {
                $matches = true;
            } elseif ($activeLabel !== null && $activeLabel === $meta['household_label']) {
                $matches = true;
            } elseif (strtolower($account->email()) === strtolower($meta['email'])) {
                $matches = true;
            }

            if ($matches) {
                $activeScenarioId = $id;
                break;
            }
        }

        foreach (DemoScenarioSeeder::SCENARIOS as $id => $meta) {
            $isActive = ($id === $activeScenarioId);
            $item = [
                'id' => $meta['id'],
                'name' => $meta['name'],
                'description' => $meta['purpose'] ?? '',
                'narrative' => $meta['narrative'] ?? $meta['purpose'] ?? '',
                'household_label' => $meta['household_label'] ?? $meta['name'],
                'location' => $meta['location'] ?? 'Kodumur / Andhra Pradesh',
                'solar_capacity_kw' => $meta['solar_capacity_kw'] ?? 3.0,
                'is_active' => $isActive,
            ];
            $scenarios[] = $item;
            if ($isActive) {
                $activeScenario = $item;
            }
        }

        return Response::success([
            'scenarios' => $scenarios,
            'active_scenario' => $activeScenario,
        ]);
    }

    /**
     * POST /api/v1/demo/scenarios/switch
     */
    public function switch(Request $request): Response
    {
        $account = Authorization::requireAccount($request);
        $this->assertDemoEnvironment($account);

        $scenarioId = $request->bodyParam('scenario_id');
        if (!is_string($scenarioId) || !isset(DemoScenarioSeeder::SCENARIOS[$scenarioId])) {
            throw new AppException(
                'VALIDATION_ERROR',
                'Invalid or unsupported scenario identifier. Must be one of: ' . implode(', ', array_keys(DemoScenarioSeeder::SCENARIOS)),
                422,
                ['field' => 'scenario_id']
            );
        }

        $meta = DemoScenarioSeeder::SCENARIOS[$scenarioId];
        $pdo = Database::connection();
        $cleanSn = str_replace('-', '', (string) $meta['service_number']);

        $stmt = $pdo->prepare("SELECT * FROM households WHERE (service_number = :sn OR REPLACE(service_number, '-', '') = :clean_sn OR label = :label) AND status = 'ACTIVE' LIMIT 1");
        $stmt->execute([
            ':sn' => $meta['service_number'],
            ':clean_sn' => $cleanSn,
            ':label' => $meta['household_label'],
        ]);
        $hhRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($hhRow === false) {
            throw new AppException(
                'SCENARIO_HOUSEHOLD_NOT_FOUND',
                "Scenario household for '{$scenarioId}' is not seeded in the active demo database.",
                404
            );
        }

        $targetHousehold = Household::fromStorageRow($hhRow);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // Check if account already has a membership row
        $memStmt = $pdo->prepare('SELECT id FROM household_memberships WHERE account_id = :acc_id LIMIT 1');
        $memStmt->execute([':acc_id' => $account->id()]);
        $existingMemId = $memStmt->fetchColumn();

        if ($existingMemId !== false) {
            $updateStmt = $pdo->prepare('UPDATE household_memberships SET household_id = :hh_id, role = :role, updated_at = :updated_at WHERE id = :id');
            $updateStmt->execute([
                ':hh_id' => $targetHousehold->id(),
                ':role' => 'OWNER',
                ':updated_at' => $now,
                ':id' => $existingMemId,
            ]);
        } else {
            $insertStmt = $pdo->prepare('INSERT INTO household_memberships (id, household_id, account_id, role, created_at, updated_at) VALUES (:id, :hh_id, :acc_id, :role, :created_at, :updated_at)');
            $insertStmt->execute([
                ':id' => self::uuid(),
                ':hh_id' => $targetHousehold->id(),
                ':acc_id' => $account->id(),
                ':role' => 'OWNER',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }

        // Session rotation & regeneration (session fixation defense)
        $priorSession = $request->authContext()?->session();
        $freshSession = $this->sessions->start($account->id(), $priorSession);

        $activeMeta = [
            'id' => $meta['id'],
            'name' => $meta['name'],
            'description' => $meta['purpose'] ?? '',
            'narrative' => $meta['narrative'] ?? $meta['purpose'] ?? '',
            'household_label' => $meta['household_label'] ?? $meta['name'],
            'location' => $meta['location'] ?? 'Kodumur / Andhra Pradesh',
            'solar_capacity_kw' => $meta['solar_capacity_kw'] ?? 3.0,
            'is_active' => true,
        ];

        return Response::success([
            'switched' => true,
            'active_scenario' => $activeMeta,
            'account' => $account->toPublicArray(),
            'household' => $targetHousehold->toPublicArray(),
            'session' => [
                'csrf_token' => $freshSession['csrf_token'],
            ],
        ])->withHeader('Set-Cookie', SessionCookie::issueHeaderValue($freshSession['raw_token']));
    }

    /**
     * Strict environment and account guard.
     */
    private function assertDemoEnvironment(Account $account): void
    {
        $profile = Database::currentProfile();
        if ($profile === DataEnvironments::REAL) {
            throw new AppException(
                'REFUSED_IN_REAL_ENVIRONMENT',
                'Demo scenario operations are refused in the REAL data environment.',
                403
            );
        }

        if ($account->mode() !== Account::MODE_DEMO_TEST) {
            throw new AppException(
                'DEMO_MODE_REQUIRED',
                'Only demo accounts can access demo scenarios.',
                403
            );
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
