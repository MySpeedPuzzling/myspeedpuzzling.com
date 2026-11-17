<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Also thrown for a display of another event than the one being changed, and for a round to follow that is not the
 * display's event's - nobody learns about other organisers' displays or rounds.
 */
final class CompetitionDisplayNotFound extends NotFoundHttpException
{
}
