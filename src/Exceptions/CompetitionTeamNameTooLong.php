<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Forms validate the length first (CompetitionTeamNames), so this guards the entity, not the visitor's input.
 */
final class CompetitionTeamNameTooLong extends UnprocessableEntityHttpException
{
}
