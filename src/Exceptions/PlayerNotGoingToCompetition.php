<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Only a player going to the event (a competition_participant row that is not deleted) can bring puzzles to it.
 */
final class PlayerNotGoingToCompetition extends NotFoundHttpException
{
}
