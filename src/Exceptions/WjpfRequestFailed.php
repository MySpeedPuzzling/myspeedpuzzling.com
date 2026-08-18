<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The WJPF endpoint could not be reached, or answered with something we refuse to act on.
 *
 * A player who simply is not in their database is NOT a failure - that is a null result.
 */
final class WjpfRequestFailed extends RuntimeException
{
    public static function transportError(string $reason, null|Throwable $previous = null): self
    {
        return new self(sprintf('Request to WJPF failed: %s', $reason), 0, $previous);
    }

    public static function unreadableResponse(string $body): self
    {
        return new self(sprintf('WJPF returned a response that is not JSON: %s', self::truncate($body)));
    }

    public static function remoteError(string $message, int|string $errorCode): self
    {
        return new self(sprintf('WJPF returned error %s: %s', $errorCode, $message));
    }

    public static function missingPlayerId(string $body): self
    {
        return new self(sprintf('WJPF response has no usable IdJugador: %s', self::truncate($body)));
    }

    private static function truncate(string $body): string
    {
        return mb_strlen($body) > 500 ? mb_substr($body, 0, 500) . '...' : $body;
    }
}
