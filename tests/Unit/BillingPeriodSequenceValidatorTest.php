<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Bill\Bill;
use App\Domain\Bill\BillStatus;
use App\Domain\Bill\Sequence\BillingPeriodSequenceValidator;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Assert;
use Tests\TestResult;

final class BillingPeriodSequenceValidatorTest
{
    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-21 12:00:00', new DateTimeZone('UTC'));
    }

    private static function makeVerifiedBill(string $start, string $end): Bill
    {
        return Bill::create('hh-test', $start, $end, self::now())
            ->transitionTo(BillStatus::PROCESSING, self::now())
            ->transitionTo(BillStatus::EXTRACTED, self::now())
            ->verify(self::now());
    }

    public static function run(): array
    {
        $validator = new BillingPeriodSequenceValidator();

        return [
            TestResult::check('FIRST_BILL returned when household has no verified bills', function () use ($validator): void {
                $result = $validator->validate('2024-01-01', '2024-01-31', []);

                Assert::same(BillingPeriodSequenceValidator::FIRST_BILL, $result->status());
                Assert::true($result->canVerify());
                Assert::false($result->requiresAcknowledgment());
            }),

            TestResult::check('CONTIGUOUS returned when candidate directly follows previous verified bill', function () use ($validator): void {
                $previous = self::makeVerifiedBill('2024-01-01', '2024-01-31');

                $result = $validator->validate('2024-02-01', '2024-02-29', [$previous]);

                Assert::same(BillingPeriodSequenceValidator::CONTIGUOUS, $result->status());
                Assert::true($result->canVerify());
                Assert::false($result->requiresAcknowledgment());
            }),

            TestResult::check('CONTIGUOUS tolerates realistic meter reading cycle shift of <= 5 days', function () use ($validator): void {
                // Reading cycle shift: Jan 31 reading, next cycle begins Feb 3 (2 days difference)
                $previous = self::makeVerifiedBill('2024-01-01', '2024-01-31');

                $result = $validator->validate('2024-02-03', '2024-03-02', [$previous]);

                Assert::same(BillingPeriodSequenceValidator::CONTIGUOUS, $result->status());
                Assert::true($result->canVerify());
            }),

            TestResult::check('GAP_DETECTED returned and can be accepted when historical month is missing', function () use ($validator): void {
                // Previous bill ends in January, new bill starts in April (missing Feb + March)
                $previous = self::makeVerifiedBill('2024-01-01', '2024-01-31');

                $result = $validator->validate('2024-04-01', '2024-04-30', [$previous]);

                Assert::same(BillingPeriodSequenceValidator::GAP_DETECTED, $result->status());
                Assert::false($result->canVerify());
                Assert::true($result->requiresAcknowledgment(), 'billing gaps must require controlled acknowledgement');
                Assert::true($result->missingPeriod() !== null);
                Assert::same('2024-01-31', $result->missingPeriod()['start']);
                Assert::same('2024-04-01', $result->missingPeriod()['end']);
            }),

            TestResult::check('DUPLICATE_PERIOD blocks verification when candidate exactly matches verified bill', function () use ($validator): void {
                $existing = self::makeVerifiedBill('2024-03-01', '2024-03-31');

                $result = $validator->validate('2024-03-01', '2024-03-31', [$existing]);

                Assert::same(BillingPeriodSequenceValidator::DUPLICATE_PERIOD, $result->status());
                Assert::false($result->canVerify());
                Assert::false($result->requiresAcknowledgment(), 'duplicates can never be accepted');
            }),

            TestResult::check('OVERLAPPING_PERIOD blocks verification when candidate overlaps verified bill', function () use ($validator): void {
                $existing = self::makeVerifiedBill('2024-03-01', '2024-03-31');

                $result = $validator->validate('2024-03-15', '2024-04-15', [$existing]);

                Assert::same(BillingPeriodSequenceValidator::OVERLAPPING_PERIOD, $result->status());
                Assert::false($result->canVerify());
                Assert::false($result->requiresAcknowledgment(), 'overlaps can never be accepted');
            }),

            TestResult::check('INVALID_REVERSED returned when start is after end date', function () use ($validator): void {
                $result = $validator->validate('2024-04-30', '2024-04-01', []);

                Assert::same(BillingPeriodSequenceValidator::INVALID_REVERSED, $result->status());
                Assert::false($result->canVerify());
                Assert::false($result->requiresAcknowledgment());
            }),

            TestResult::check('regression: contiguous monthly periods (2026-06-01 to 2026-07-01 and 2026-07-01 to 2026-08-01) produce NO GAP', function () use ($validator): void {
                $august = self::makeVerifiedBill('2026-07-01', '2026-08-01');
                $september = self::makeVerifiedBill('2026-08-01', '2026-09-01');

                // Validating July: period ends on 2026-07-01, touching August's start 2026-07-01
                $resultJuly = $validator->validate('2026-06-01', '2026-07-01', [$august, $september]);
                Assert::same(BillingPeriodSequenceValidator::CONTIGUOUS, $resultJuly->status(), 'July must be contiguous with August');
                Assert::true($resultJuly->canVerify());
                Assert::false($resultJuly->requiresAcknowledgment());
                Assert::same(null, $resultJuly->missingPeriod());

                // Validating August: contiguous between July and September
                $july = self::makeVerifiedBill('2026-06-01', '2026-07-01');
                $resultAugust = $validator->validate('2026-07-01', '2026-08-01', [$july, $september]);
                Assert::same(BillingPeriodSequenceValidator::CONTIGUOUS, $resultAugust->status(), 'August must be contiguous between July and September');
                Assert::true($resultAugust->canVerify());
                Assert::false($resultAugust->requiresAcknowledgment());
            }),

            TestResult::check('regression: genuine gap (2026-06-01 to 2026-07-01 and 2026-09-01 to 2026-10-01) produces GAP_DETECTED', function () use ($validator): void {
                $july = self::makeVerifiedBill('2026-06-01', '2026-07-01');

                // October statement proposed when only July exists (August & September missing)
                $result = $validator->validate('2026-09-01', '2026-10-01', [$july]);
                Assert::same(BillingPeriodSequenceValidator::GAP_DETECTED, $result->status(), 'missing months must produce GAP_DETECTED');
                Assert::false($result->canVerify());
                Assert::true($result->requiresAcknowledgment());
                Assert::true($result->missingPeriod() !== null);
                Assert::same('2026-07-01', $result->missingPeriod()['start']);
                Assert::same('2026-09-01', $result->missingPeriod()['end']);
            }),
        ];
    }
}
