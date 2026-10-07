<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Every writer validates the length first (the import, the participants spreadsheet - `team_name_too_long`), so this
 * guards the entity, not the visitor's input.
 */
final class CompetitionTeamNameTooLong extends UnprocessableEntityHttpException
{
}
