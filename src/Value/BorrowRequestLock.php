<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Lock keys (SerializedByLock) of the "ask to borrow" writes: an asker's sends take turns, so a resent form or a double
 * click never creates the same request twice; answers and withdrawals take turns per request.
 */
final readonly class BorrowRequestLock
{
    public static function askerKey(string $askerId): string
    {
        return 'borrow-ask-' . strtolower($askerId);
    }

    public static function requestKey(string $requestId): string
    {
        return 'borrow-request-' . strtolower($requestId);
    }
}
