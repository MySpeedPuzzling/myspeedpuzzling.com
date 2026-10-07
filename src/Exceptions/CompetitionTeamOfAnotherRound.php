<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A participant joins only a team of the round they are entered in.
 */
final class CompetitionTeamOfAnotherRound extends UnprocessableEntityHttpException
{
}
