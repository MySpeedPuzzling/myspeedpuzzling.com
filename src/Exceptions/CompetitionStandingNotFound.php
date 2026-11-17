<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Also thrown for a standing of another event than the one being changed - nobody learns about other organisers'
 * standings.
 */
final class CompetitionStandingNotFound extends NotFoundHttpException
{
}
