<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A call to LiveKit's server API did not do what was asked (LiveKitApi): video is off (no server configured), the
 * server could not be reached, or it refused - `twirpCode` is its error code (`not_found` for a room or a participant
 * that is not there, `permission_denied`, `invalid_argument`, …), `httpStatus` its HTTP status.
 *
 * Callers run after the commit of what the call follows up on (StudioLiveUpdates): they log it, never fail the request.
 */
final class LiveKitRequestFailed extends RuntimeException
{
    public const string NOT_CONFIGURED = 'not_configured';
    public const string UNREACHABLE = 'unreachable';
    public const string NOT_FOUND = 'not_found';

    private function __construct(
        string $message,
        readonly public string $method,
        readonly public string $twirpCode,
        readonly public null|int $httpStatus = null,
        null|Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(string $method): self
    {
        return new self(sprintf('LiveKit %s: no LiveKit server is configured (LIVEKIT_* is empty)', $method), $method, self::NOT_CONFIGURED);
    }

    public static function unreachable(string $method, Throwable $previous): self
    {
        return new self(sprintf('LiveKit %s: the server could not be reached', $method), $method, self::UNREACHABLE, null, $previous);
    }

    public static function refused(string $method, int $httpStatus, string $twirpCode, string $reason): self
    {
        return new self(sprintf('LiveKit %s answered %d %s: %s', $method, $httpStatus, $twirpCode, $reason), $method, $twirpCode, $httpStatus);
    }

    /**
     * The room or the participant is not there - nothing to change
     */
    public function isNotFound(): bool
    {
        return $this->twirpCode === self::NOT_FOUND;
    }
}
