<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The id sent with a new result belongs to a result of the same player with another puzzle, time or day - not the
 * same entry sent again, but a different result reusing the id (docs/features/duplicate-results.md, Layer 1).
 * Nothing is saved: the add form gets a fresh id, the API answers 422 `idempotency_key_reused`.
 */
final class SolvingTimeIdReused extends \Exception
{
    public function __construct()
    {
        parent::__construct('The result id already belongs to a different result of this player.');
    }
}
