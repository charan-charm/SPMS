<?php

declare(strict_types=1);

namespace Tests\API;

use App\Controllers\NotificationController;
use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationService;
use App\Repositories\NotificationRepository;
use App\Support\AppException;
use App\Support\Database;
use App\Support\Request;
use App\Support\RequestId;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\Assert;
use Tests\Support\AuthTesting;
use Tests\TestResult;

final class NotificationApiTest
{
    private static function pdo(): PDO
    {
        return Database::connection();
    }

    private static function controller(): NotificationController
    {
        AuthTesting::configure();
        return new NotificationController();
    }

    public static function run(): array
    {
        return [
            TestResult::check('GET /api/v1/notifications requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->index(Request::create('GET', '/api/v1/notifications'));
                    Assert::true(false, 'Expected AppException for unauthenticated request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('POST /api/v1/notifications/{id}/read requires authentication', function (): void {
                RequestId::set(RequestId::new());

                try {
                    self::controller()->markRead(Request::create('POST', '/api/v1/notifications/00000000-0000-0000-0000-000000000001/read'));
                    Assert::true(false, 'Expected AppException for unauthenticated request');
                } catch (AppException $e) {
                    Assert::same('UNAUTHENTICATED', $e->errorCode());
                    Assert::same(401, $e->httpStatus());
                }
            }),

            TestResult::check('Empty notifications list returns empty array and zero counts', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/notifications'), $fixture['account']);
                $res = self::controller()->index($req);
                Assert::same(200, $res->status());

                $data = $res->data();
                Assert::true(isset($data['notifications']));
                Assert::same(0, $data['unread_count']);
                Assert::same(0, $data['total_count']);
            }),

            TestResult::check('Notification creation, listing ordering, and unread counts', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $householdId = $fixture['household_id'];

                $service = new NotificationService();

                // Create first notification
                $n1 = $service->notify(
                    householdId: $householdId,
                    type: Notification::TYPE_SOLAR,
                    severity: Notification::SEVERITY_SUCCESS,
                    title: 'Solar Connected',
                    message: 'Inverter connected successfully.',
                    relatedResourceType: 'SOLAR',
                    relatedResourceId: 'st-01',
                    eventKey: 'test_solar_conn_' . $householdId,
                    now: new DateTimeImmutable('2026-09-25 10:00:00', new DateTimeZone('UTC'))
                );
                Assert::true($n1 !== null);

                // Create second notification (newer)
                $n2 = $service->notify(
                    householdId: $householdId,
                    type: Notification::TYPE_BILL,
                    severity: Notification::SEVERITY_WARNING,
                    title: 'Bill Processing Alert',
                    message: 'Bill processing requires manual review.',
                    relatedResourceType: 'BILL',
                    relatedResourceId: 'bill-01',
                    eventKey: 'test_bill_alert_' . $householdId,
                    now: new DateTimeImmutable('2026-09-25 11:00:00', new DateTimeZone('UTC'))
                );
                Assert::true($n2 !== null);

                $req = AuthTesting::authed(Request::create('GET', '/api/v1/notifications'), $fixture['account']);
                $res = self::controller()->index($req);
                Assert::same(200, $res->status());

                $data = $res->data();
                Assert::same(2, $data['total_count']);
                Assert::same(2, $data['unread_count']);
                Assert::same(2, count($data['notifications']));

                // Newest first order
                Assert::same($n2->id(), $data['notifications'][0]['id']);
                Assert::same('Bill Processing Alert', $data['notifications'][0]['title']);
                Assert::same($n1->id(), $data['notifications'][1]['id']);
                Assert::same('Solar Connected', $data['notifications'][1]['title']);
            }),

            TestResult::check('Duplicate prevention via event_key', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $householdId = $fixture['household_id'];

                $service = new NotificationService();
                $eventKey = 'unique_event_' . bin2hex(random_bytes(6));

                $first = $service->notify(
                    householdId: $householdId,
                    type: Notification::TYPE_SYSTEM,
                    severity: Notification::SEVERITY_INFO,
                    title: 'First Notification',
                    message: 'Message 1',
                    eventKey: $eventKey
                );
                Assert::true($first !== null);

                // Attempting second with same event_key must return null and not insert
                $second = $service->notify(
                    householdId: $householdId,
                    type: Notification::TYPE_SYSTEM,
                    severity: Notification::SEVERITY_INFO,
                    title: 'Second Notification',
                    message: 'Message 2',
                    eventKey: $eventKey
                );
                Assert::true($second === null);
            }),

            TestResult::check('Mark notification as read updates read state and reduces unread count', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $householdId = $fixture['household_id'];

                $service = new NotificationService();
                $n = $service->notify(
                    householdId: $householdId,
                    type: Notification::TYPE_RECOMMENDATION,
                    severity: Notification::SEVERITY_INFO,
                    title: 'Rec Generated',
                    message: 'A recommendation is ready.',
                    eventKey: 'rec_test_' . bin2hex(random_bytes(6))
                );
                Assert::true($n !== null);

                $readReq = AuthTesting::authed(
                    Request::create('POST', "/api/v1/notifications/{$n->id()}/read"),
                    $fixture['account']
                );

                $readRes = self::controller()->markRead($readReq);
                Assert::same(200, $readRes->status());

                $readBody = $readRes->data();
                Assert::same('OK', $readBody['status']);
                Assert::same($n->id(), $readBody['id']);
                Assert::true($readBody['is_read']);

                // Verify via repository
                $repo = new NotificationRepository();
                $updated = $repo->findById($n->id(), $householdId);
                Assert::true($updated !== null);
                Assert::true($updated->isRead());
                Assert::true($updated->readAt() !== null);
            }),

            TestResult::check('Mark all notifications as read', function (): void {
                RequestId::set(RequestId::new());
                $fixture = AuthTesting::householdFixture();
                $householdId = $fixture['household_id'];

                $service = new NotificationService();
                $service->notify(
                    householdId: $householdId,
                    type: Notification::TYPE_DATA_QUALITY,
                    severity: Notification::SEVERITY_WARNING,
                    title: 'DQ Alert 1',
                    message: 'DQ issue',
                    eventKey: 'dq_1_' . bin2hex(random_bytes(6))
                );
                $service->notify(
                    householdId: $householdId,
                    type: Notification::TYPE_DATA_QUALITY,
                    severity: Notification::SEVERITY_WARNING,
                    title: 'DQ Alert 2',
                    message: 'DQ issue 2',
                    eventKey: 'dq_2_' . bin2hex(random_bytes(6))
                );

                $req = AuthTesting::authed(
                    Request::create('POST', '/api/v1/notifications/read-all'),
                    $fixture['account']
                );

                $res = self::controller()->markAllRead($req);
                Assert::same(200, $res->status());

                $repo = new NotificationRepository();
                Assert::same(0, $repo->countUnread($householdId));
            }),

            TestResult::check('Cross-household isolation on list and mark-read', function (): void {
                RequestId::set(RequestId::new());
                $h1 = AuthTesting::householdFixture();
                $h2 = AuthTesting::householdFixture();

                $service = new NotificationService();
                $n1 = $service->notify(
                    householdId: $h1['household_id'],
                    type: Notification::TYPE_SYSTEM,
                    severity: Notification::SEVERITY_INFO,
                    title: 'H1 Notification',
                    message: 'Private to H1',
                    eventKey: 'h1_private_' . bin2hex(random_bytes(6))
                );

                // H2 lists notifications — must not see H1's notification
                $h2Req = AuthTesting::authed(Request::create('GET', '/api/v1/notifications'), $h2['account']);
                $h2Res = self::controller()->index($h2Req);
                $h2Data = $h2Res->data();
                foreach ($h2Data['notifications'] as $item) {
                    Assert::true($item['id'] !== $n1->id());
                }

                // H2 attempts to mark H1's notification as read — must return 404
                $crossReq = AuthTesting::authed(
                    Request::create('POST', "/api/v1/notifications/{$n1->id()}/read"),
                    $h2['account']
                );
                try {
                    self::controller()->markRead($crossReq);
                    Assert::true(false, 'Expected 404 for cross-household notification read');
                } catch (AppException $e) {
                    Assert::same(404, $e->httpStatus());
                    Assert::same('NOT_FOUND', $e->errorCode());
                }
            }),
        ];
    }
}
