<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Bill\BillTempDocument;
use App\Domain\Bill\BillUpload;
use App\Domain\Bill\BillUploadIntakeService;
use App\Services\Files\BillStorageException;
use App\Services\Files\LocalPrivateBillStorage;
use App\Support\AppException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * B2 — Pure intake unit checks (docs/10 D-068): fail-closed signature /
 * extension / size gates, dangerous-name inertness, opaque key generation,
 * temporary-document expiry/state, storage primitives and the private
 * storage-root policy. No database, no HTTP, no OCR — synthetic
 * signature fixtures only.
 */
final class BillUploadTest
{
    /** @var list<string> */
    private static array $tmpFiles = [];

    private static function fixture(string $bytes, string $suffix): string
    {
        $path = sys_get_temp_dir() . '/spms-b2-' . bin2hex(random_bytes(6)) . $suffix;
        file_put_contents($path, $bytes);
        self::$tmpFiles[] = $path;

        return $path;
    }

    private static function fakeUploadVerifier(): callable
    {
        // Offline synthetic fixtures are not real HTTP uploads; the seam
        // lets the same validation run without Apache/PHP upload state.
        return static fn (string $path): bool => is_file($path);
    }

    private static function spec(string $path, string $name, int $error = UPLOAD_ERR_OK): array
    {
        return [
            'name' => $name,
            'type' => 'application/octet-stream', // client-declared: never trusted
            'tmp_name' => $path,
            'error' => $error,
            'size' => 999999, // browser-claimed: never trusted
        ];
    }

    private static function valid(int $error = UPLOAD_ERR_OK): BillUpload
    {
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 64);
        $path = self::fixture($jpeg, '.jpg');

        return BillUpload::fromFilesSpec(self::spec($path, 'electricity-bill.jpg', $error), self::fakeUploadVerifier());
    }

    public static function run(): array
    {
        return [
            TestResult::check('ratified upload ceiling is exactly 25 MiB', function (): void {
                Assert::same(25 * 1024 * 1024, BillUpload::MAX_BILL_UPLOAD_BYTES);
            }),

            TestResult::check('valid JPEG/PNG/PDF pass on ACTUAL signature, not declared type/size', function (): void {
                $jpeg = "\xFF\xD8\xFF\xDB" . str_repeat('x', 40);
                $png = "\x89PNG\r\n\x1a\n" . str_repeat('x', 40);
                $pdf = "%PDF-1.7\n" . str_repeat('x', 40);

                $j = BillUpload::fromFilesSpec(self::spec(self::fixture($jpeg, '.jpg'), 'a.jpg'), self::fakeUploadVerifier());
                $p = BillUpload::fromFilesSpec(self::spec(self::fixture($png, '.png'), 'b.png'), self::fakeUploadVerifier());
                $d = BillUpload::fromFilesSpec(self::spec(self::fixture($pdf, '.pdf'), 'c.pdf'), self::fakeUploadVerifier());

                Assert::same('image/jpeg', $j->mimeType());
                Assert::same('image/png', $p->mimeType());
                Assert::same('application/pdf', $d->mimeType());

                // size measured from disk, not the bogus spec['size']=999999.
                Assert::same(strlen($jpeg), $j->sizeBytes());
                Assert::true($j->sizeBytes() !== 999999, 'browser size claim must be ignored');
            }),

            TestResult::check('magic-signature mismatch fails closed', function (): void {
                // .png extension but JPEG bytes.
                $fake = "\xFF\xD8\xFF" . str_repeat('x', 20);
                $path = self::fixture($fake, '.png');
                Assert::throws(
                    AppException::class,
                    static fn () => BillUpload::fromFilesSpec(self::spec($path, 'bill.png'), self::fakeUploadVerifier()),
                    'content not matching claimed type must throw'
                );

                try {
                    BillUpload::fromFilesSpec(self::spec($path, 'bill.png'), self::fakeUploadVerifier());
                } catch (AppException $e) {
                    Assert::same('INVALID_FILE_SIGNATURE', $e->errorCode());
                }
            }),

            TestResult::check('unsupported / executable extensions rejected (renamed is irrelevant)', function (): void {
                foreach (['x.php', 'x.phtml', 'x.phar', 'x.exe', 'x.svg', 'x.html', 'x.js'] as $name) {
                    $path = self::fixture("%PDF-1.7\nwhatever", '.' . pathinfo($name, PATHINFO_EXTENSION));
                    Assert::throws(
                        AppException::class,
                        static fn () => BillUpload::fromFilesSpec(self::spec($path, $name), self::fakeUploadVerifier()),
                        $name . ' must be refused'
                    );

                    try {
                        BillUpload::fromFilesSpec(self::spec($path, $name), self::fakeUploadVerifier());
                    } catch (AppException $e) {
                        Assert::same('UNSUPPORTED_FILE_TYPE', $e->errorCode());
                    }
                }
            }),

            TestResult::check('dangerous names cannot reach a storage path (opaque key from the record UUID)', function (): void {
                $evil = '../../../../etc/passwd.php';
                $jpeg = "\xFF\xD8\xFF" . str_repeat('x', 20);
                $path = self::fixture($jpeg, '.jpg');

                // The extension tail (.php) is unsupported regardless of the
                // traversal; even a traversal-shaped name ending .jpg yields an
                // opaque UUID key that never embeds the client name.
                Assert::throws(
                    AppException::class,
                    static fn () => BillUpload::fromFilesSpec(self::spec($path, $evil), self::fakeUploadVerifier())
                );

                $weird = self::fixture($jpeg, '.jpg');
                $upload = BillUpload::fromFilesSpec(
                    self::spec($weird, "spaces and \tweird-name.jpg"), // control chars in the NAME change nothing: it is never used
                    self::fakeUploadVerifier()
                );

                $doc = BillTempDocument::create(
                    '00000000-0000-4000-8000-000000000001',
                    'image/png',
                    23,
                    24,
                    new DateTimeImmutable('2026-09-14 10:00:00', new DateTimeZone('UTC'))
                );
                Assert::true(
                    preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(png|jpg|pdf)$/', $doc->storageKey()) === 1,
                    'storage key must be an opaque UUID + canonical extension: ' . $doc->storageKey()
                );
                Assert::notContains('passwd', $doc->storageKey());
                Assert::notContains('..', $doc->storageKey());
                Assert::same('image/jpeg', $upload->mimeType(), 'canonical type comes from the signature check, not the name');
            }),

            TestResult::check('empty file, PHP upload errors and oversize fail with distinct safe codes', function (): void {
                $empty = self::fixture('', '.jpg');
                try {
                    BillUpload::fromFilesSpec(self::spec($empty, 'e.jpg'), self::fakeUploadVerifier());
                    Assert::true(false, 'empty must throw');
                } catch (AppException $e) {
                    Assert::same('EMPTY_FILE', $e->errorCode());
                    Assert::same(422, $e->httpStatus());
                }

                // UPLOAD_ERR_PARTIAL maps to INVALID_UPLOAD; INI_SIZE to FILE_TOO_LARGE.
                $jpeg = self::fixture("\xFF\xD8\xFFabc", '.jpg');
                try {
                    BillUpload::fromFilesSpec(self::spec($jpeg, 'p.jpg', UPLOAD_ERR_PARTIAL), self::fakeUploadVerifier());
                    Assert::true(false, 'partial must throw');
                } catch (AppException $e) {
                    Assert::same('INVALID_UPLOAD', $e->errorCode());
                }

                try {
                    BillUpload::fromFilesSpec(self::spec($jpeg, 's.jpg', UPLOAD_ERR_INI_SIZE), self::fakeUploadVerifier());
                    Assert::true(false, 'ini-size must throw');
                } catch (AppException $e) {
                    Assert::same('FILE_TOO_LARGE', $e->errorCode());
                }

                // Real byte-size ceiling enforced by injected maximum (test bound).
                $big = self::fixture("\xFF\xD8\xFF" . str_repeat('z', 200), '.jpg');
                try {
                    BillUpload::fromFilesSpec(self::spec($big, 'big.jpg'), self::fakeUploadVerifier(), 50);
                    Assert::true(false, 'oversize must throw');
                } catch (AppException $e) {
                    Assert::same('FILE_TOO_LARGE', $e->errorCode());
                }
            }),

            TestResult::check('missing / unreadable temp file is rejected (no filesystem escape)', function (): void {
                $gone = self::fixture("\xFF\xD8\xFF", '.jpg');
                unlink($gone);

                try {
                    BillUpload::fromFilesSpec(self::spec($gone, 'g.jpg'), self::fakeUploadVerifier());
                    Assert::true(false, 'missing temp must throw');
                } catch (AppException $e) {
                    Assert::same('UPLOAD_FAILED', $e->errorCode());
                }

                // NUL byte in tmp_name is rejected before any fs call.
                try {
                    BillUpload::fromFilesSpec(self::spec("/tmp/de\x00l.jpg", 'x.jpg'), self::fakeUploadVerifier());
                    Assert::true(false, 'NUL tmp_name must throw');
                } catch (AppException $e) {
                    Assert::same('INVALID_UPLOAD', $e->errorCode());
                }
            }),

            TestResult::check('temporary document records expiry and one-way AVAILABLE->DELETED state', function (): void {
                $now = new DateTimeImmutable('2026-09-14 10:00:00', new DateTimeZone('UTC'));
                $doc = BillTempDocument::create(
                    '00000000-0000-4000-8000-000000000002',
                    'application/pdf',
                    4096,
                    24,
                    $now
                );

                Assert::same(BillTempDocument::STATE_AVAILABLE, $doc->state());
                Assert::same(null, $doc->deletedAt());
                Assert::true($doc->expiresAt() > $doc->createdAt(), 'expiry recorded in the future');
                Assert::false($doc->isExpiredAt($now->modify('+23 hours')), 'not expired within retention');
                Assert::true($doc->isExpiredAt($now->modify('+25 hours')), 'expired past retention');

                $deleted = $doc->markDeleted($now->modify('+24 hours'));
                Assert::same(BillTempDocument::STATE_DELETED, $deleted->state());
                Assert::true($deleted->deletedAt() !== null);
                // idempotent: re-deleting a DELETED doc returns the same state
                Assert::same(BillTempDocument::STATE_DELETED, $deleted->markDeleted($now->modify('+48 hours'))->state());

                // round-trips through storage row verbatim
                $rebuilt = BillTempDocument::fromStorageRow($doc->toStorageRow());
                Assert::same($doc->storageKey(), $rebuilt->storageKey());
                Assert::same($doc->mimeType(), $rebuilt->mimeType());
                Assert::same($doc->sizeBytes(), $rebuilt->sizeBytes());

                // out-of-range retention/size are refused
                Assert::throws(InvalidArgumentException::class, static fn () => BillTempDocument::create(
                    '00000000-0000-4000-8000-000000000003', 'image/png', 10, 999, $now
                ));
                Assert::throws(InvalidArgumentException::class, static fn () => BillTempDocument::create(
                    '00000000-0000-4000-8000-000000000004', 'image/png', 0, 24, $now
                ));
                Assert::throws(InvalidArgumentException::class, static fn () => BillTempDocument::create(
                    '00000000-0000-4000-8000-000000000005', 'text/html', 10, 24, $now
                ));
            }),

            TestResult::check('retention is configurable within a safe bounded range', function (): void {
                $hours = BillUploadIntakeService::retentionHours();

                Assert::true($hours >= 1 && $hours <= 168, 'retention must stay within 1..168 hours, got ' . $hours);
                Assert::same(24, $hours, 'no .env value exists for this key in the automated suite => ratified 24h default');
            }),

            TestResult::check('local private storage: store/read/delete are byte-exact and idempotent', function (): void {
                $root = sys_get_temp_dir() . '/spms-b2-store-' . bin2hex(random_bytes(4));
                $storage = new LocalPrivateBillStorage($root);

                $key = '0f0f0f0f-0f0f-4f0f-8f0f-0f0f0f0f0f0f.jpg';
                $bytes = "\xFF\xD8\xFF" . random_bytes(128);

                $storage->store($key, $bytes);
                Assert::true($storage->exists($key));
                Assert::same($bytes, $storage->read($key));

                Assert::true($storage->delete($key));
                Assert::false($storage->exists($key));
                Assert::same(null, $storage->read($key));
                // idempotent delete of an already-gone key
                Assert::true($storage->delete($key));
                Assert::true($storage->delete($key));

                @rmdir($root);
            }),

            TestResult::check('storage refuses keys that are not opaque safe names (no traversal / exec)', function (): void {
                $root = sys_get_temp_dir() . '/spms-b2-store-' . bin2hex(random_bytes(4));
                $storage = new LocalPrivateBillStorage($root);

                foreach ([
                    '../../evil.jpg',
                    '..\\..\\evil.jpg',
                    'C:\\windows\\system32\\x.jpg',
                    "abc.jpg\0.png",
                    'not-a-uuid.jpg',
                    '0f0f0f0f-0f0f-4f0f-8f0f-0f0f0f0f0f0f.php',
                ] as $badKey) {
                    Assert::throws(
                        BillStorageException::class,
                        static fn () => $storage->store($badKey, 'x'),
                        'unsafe key must be refused: ' . $badKey
                    );
                }
            }),

            TestResult::check('private storage root default is under storage/private and never under public/', function (): void {
                $root = LocalPrivateBillStorage::defaultRoot(null);
                $normalized = str_replace('\\', '/', $root);

                Assert::contains('/storage/private/bills/tmp', $normalized);
                Assert::false(str_contains($normalized, '/public/'), 'uploads must never live inside a web-accessible path');
            }),
        ];
    }
}
