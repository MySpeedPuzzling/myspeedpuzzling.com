<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * An internal API refusal a script tells apart by a stable word rather than by its message - answered with
 * `"code": "…"` next to `"error"` (InternalApiErrorResponseSubscriber). Never rename a code: callers branch on it.
 */
interface InternalApiErrorCode
{
    public function internalApiErrorCode(): string;
}
