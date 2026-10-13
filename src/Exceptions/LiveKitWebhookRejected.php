<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A request to `POST /-/livekit-webhook` that is no webhook of our LiveKit server (LiveKitWebhookVerifier): no or a
 * malformed signature, a signature of another key, an expired one, or a body that is not the one signed.
 */
final class LiveKitWebhookRejected extends RuntimeException
{
    public static function because(string $reason, null|Throwable $previous = null): self
    {
        return new self('LiveKit webhook rejected: ' . $reason, 0, $previous);
    }
}
