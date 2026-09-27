<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Household\Household;
use App\Domain\Recommendation\Recommendation;
use App\Domain\Recommendation\RecommendationConfidence;
use App\Domain\Recommendation\RecommendationTaxonomy;
use App\Repositories\HouseholdRepository;
use App\Repositories\RecommendationRepository;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Tests\Support\Assert;
use Tests\TestResult;

final class RecommendationPersistenceTest
{
    private static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }

    public static function run(): array
    {
        return [
            TestResult::check('recommendations table exists with canonical schema and indices', function (): void {
                $pdo = Database::connection();
                $stmt = $pdo->query('SHOW TABLES LIKE "recommendations"');
                Assert::same('recommendations', $stmt->fetchColumn(), 'recommendations table must exist');

                $colStmt = $pdo->query('DESCRIBE recommendations');
                $columns = $colStmt->fetchAll(\PDO::FETCH_COLUMN);

                Assert::true(in_array('id', $columns, true));
                Assert::true(in_array('household_id', $columns, true));
                Assert::true(in_array('type', $columns, true));
                Assert::true(in_array('status', $columns, true));
                Assert::true(in_array('priority', $columns, true));
                Assert::true(in_array('title', $columns, true));
                Assert::true(in_array('summary', $columns, true));
                Assert::true(in_array('action', $columns, true));
                Assert::true(in_array('reason', $columns, true));
                Assert::true(in_array('timing_window', $columns, true));
                Assert::true(in_array('potential_impact', $columns, true));
                Assert::true(in_array('impact_json', $columns, true));
                Assert::true(in_array('evidence_json', $columns, true));
                Assert::true(in_array('confidence', $columns, true));
                Assert::true(in_array('quality', $columns, true));
                Assert::true(in_array('data_nature', $columns, true));
                Assert::true(in_array('provenance_json', $columns, true));
                Assert::true(in_array('limitations_json', $columns, true));
                Assert::true(in_array('relevant_period_start', $columns, true));
                Assert::true(in_array('relevant_period_end', $columns, true));
                Assert::true(in_array('valid_from', $columns, true));
                Assert::true(in_array('valid_until', $columns, true));
                Assert::true(in_array('created_at', $columns, true));
                Assert::true(in_array('updated_at', $columns, true));
                Assert::true(in_array('dismissed_at', $columns, true));
            }),

            TestResult::check('RecommendationRepository saves, queries, and lists by household', function (): void {
                $pdo = Database::connection();
                $households = new HouseholdRepository($pdo);
                $repo = new RecommendationRepository($pdo);

                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $household = Household::create('Rec Test Household', 'Asia/Kolkata', 'Kurnool', $now);
                $households->insert($household);
                $hhId = $household->id();

                $recId = self::uuid();
                $rec = new Recommendation(
                    id: $recId,
                    householdId: $hhId,
                    type: RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW,
                    status: Recommendation::STATUS_ACTIVE,
                    priority: Recommendation::PRIORITY_HIGH,
                    title: 'Shift Laundry to Solar Window',
                    summary: '3.5 kWh surplus predicted tomorrow.',
                    action: 'Run washing machine between 11:30 and 14:00.',
                    reason: 'Peak solar surplus.',
                    timingWindow: '11:30 - 14:00',
                    potentialImpact: 'Potential 3.5 kWh self-consumption.',
                    impactJson: ['type' => 'SOLAR_SELF_CONSUMPTION', 'potential_kwh_shifted' => 3.5],
                    evidenceJson: ['surplus_kwh' => 3.5],
                    confidence: RecommendationConfidence::CONFIDENCE_HIGH,
                    quality: 'GOOD',
                    dataNature: 'PREDICTED',
                    provenance: ['ForecastService'],
                    limitations: [],
                    relevantPeriodStart: '2026-09-23 11:30:00',
                    relevantPeriodEnd: '2026-09-23 14:00:00',
                    validFrom: $now,
                    validUntil: $now->modify('+24 hours'),
                    createdAt: $now,
                    updatedAt: $now
                );

                $repo->save($rec);

                // Fetch by ID
                $fetched = $repo->findById($recId);
                Assert::true($fetched !== null, 'Saved recommendation must be retrievable by ID');
                Assert::same($recId, $fetched->id());
                Assert::same($hhId, $fetched->householdId());
                Assert::same(RecommendationTaxonomy::TYPE_SHIFT_TO_SOLAR_WINDOW, $fetched->type());
                Assert::same(Recommendation::STATUS_ACTIVE, $fetched->status());
                Assert::same(Recommendation::PRIORITY_HIGH, $fetched->priority());
                Assert::same('GOOD', $fetched->quality());
                Assert::same('PREDICTED', $fetched->dataNature());

                // List by household
                $list = $repo->list(['household_id' => $hhId, 'status' => 'ACTIVE']);
                Assert::same(1, count($list));
                Assert::same($recId, $list[0]->id());
            }),

            TestResult::check('RecommendationRepository manages dismiss, deduplication, and cooldown', function (): void {
                $pdo = Database::connection();
                $households = new HouseholdRepository($pdo);
                $repo = new RecommendationRepository($pdo);

                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $household = Household::create('Rec Cooldown Household', 'Asia/Kolkata', 'Tirupati', $now);
                $households->insert($household);
                $hhId = $household->id();

                $recId = self::uuid();
                $rec = new Recommendation(
                    id: $recId,
                    householdId: $hhId,
                    type: RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW,
                    status: Recommendation::STATUS_ACTIVE,
                    priority: Recommendation::PRIORITY_HIGH,
                    title: 'Avoid Grid Deficit',
                    summary: 'Projected deficit',
                    action: 'Postpone heavy loads',
                    reason: 'Low solar',
                    timingWindow: '18:00 - 21:00',
                    potentialImpact: 'Estimated 2 kWh grid avoidance',
                    impactJson: null,
                    evidenceJson: ['deficit_kwh' => 2.0],
                    confidence: RecommendationConfidence::CONFIDENCE_HIGH,
                    quality: 'GOOD',
                    dataNature: 'PREDICTED',
                    provenance: ['ForecastService'],
                    limitations: [],
                    relevantPeriodStart: '2026-09-23 18:00:00',
                    relevantPeriodEnd: '2026-09-23 21:00:00',
                    validFrom: $now,
                    validUntil: $now->modify('+24 hours'),
                    createdAt: $now,
                    updatedAt: $now
                );
                $repo->save($rec);

                // Check active deduplication
                $existing = $repo->findActiveByPeriod($hhId, RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW, '2026-09-23 18:00:00', '2026-09-23 21:00:00');
                Assert::true($existing !== null, 'Should detect duplicate active recommendation for same period');

                // Dismiss recommendation
                $dismissTime = $now->modify('+10 minutes');
                $repo->dismiss($recId, $hhId, $dismissTime);

                $afterDismiss = $repo->findById($recId);
                Assert::true($afterDismiss !== null);
                Assert::same(Recommendation::STATUS_DISMISSED, $afterDismiss->status());
                Assert::true($afterDismiss->dismissedAt() !== null);

                // Cooldown: findRecentDismissed should return it
                $cooldown = $repo->findRecentDismissed($hhId, RecommendationTaxonomy::TYPE_AVOID_DEFICIT_WINDOW, 12, $dismissTime);
                Assert::true($cooldown !== null, 'Recently dismissed recommendation must trigger cooldown check');
            }),

            TestResult::check('Foreign key constraint prohibits orphaned recommendations', function (): void {
                $pdo = Database::connection();
                $repo = new RecommendationRepository($pdo);

                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $fakeHhId = self::uuid(); // Non-existent household

                $rec = new Recommendation(
                    id: self::uuid(),
                    householdId: $fakeHhId,
                    type: RecommendationTaxonomy::TYPE_DATA_LIMITATION,
                    status: Recommendation::STATUS_ACTIVE,
                    priority: Recommendation::PRIORITY_LOW,
                    title: 'Orphan Rec',
                    summary: 'Should fail',
                    action: 'None',
                    reason: 'FK test',
                    timingWindow: 'Immediate',
                    potentialImpact: null,
                    impactJson: null,
                    evidenceJson: [],
                    confidence: RecommendationConfidence::CONFIDENCE_LOW,
                    quality: null,
                    dataNature: 'ACTUAL',
                    provenance: ['Test'],
                    limitations: [],
                    relevantPeriodStart: null,
                    relevantPeriodEnd: null,
                    validFrom: $now,
                    validUntil: $now->modify('+1 hour'),
                    createdAt: $now,
                    updatedAt: $now
                );

                $threw = false;
                try {
                    $repo->save($rec);
                } catch (PDOException $e) {
                    $threw = true;
                    Assert::true(str_contains(strtolower($e->getMessage()), 'foreign key') || str_contains($e->getMessage(), '1452'), 'Must be a foreign key constraint violation');
                }
                Assert::true($threw, 'Saving recommendation with non-existent household must throw PDOException');
            }),
        ];
    }
}
