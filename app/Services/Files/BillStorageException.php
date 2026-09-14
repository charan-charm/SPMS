<?php

declare(strict_types=1);

namespace App\Services\Files;

use RuntimeException;

/**
 * B2 — Raised for any storage-layer fault. The message is safe to show
 * internally (never to a client): implementations must never embed the
 * absolute path or the source document name in the message.
 */
final class BillStorageException extends RuntimeException
{
}
