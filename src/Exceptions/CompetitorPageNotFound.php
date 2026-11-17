<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A competitor page that is not there for this viewer (docs/features/competitions-management/competitor-pages.md):
 * the event has competitor pages off, is a draft the viewer may not edit, the tag stands for nobody of the event, or
 * the competitor is a player hidden from the viewer (the blocklist).
 */
final class CompetitorPageNotFound extends NotFoundHttpException
{
}
