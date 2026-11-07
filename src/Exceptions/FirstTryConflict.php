<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * API v1's answer to firstAttempt=true when somebody of the result already holds a first try of the puzzle
 * (FirstTryConflictResponse, docs/features/first-try-integrity.md) - a 422 with the reason, nothing saved.
 *
 * Its own class so the refusal is logged at info (config/packages/framework.php) - the client sent a request the rules
 * refuse, nothing went wrong on our side (WEB-FC).
 */
final class FirstTryConflict extends UnprocessableEntityHttpException
{
    public function __construct(string $message, FirstTryAlreadyTaken $previous)
    {
        parent::__construct($message, $previous);
    }
}
