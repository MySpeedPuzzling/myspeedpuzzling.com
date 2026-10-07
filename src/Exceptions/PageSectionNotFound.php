<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Also thrown for a section that exists but belongs to another event or series than the one being changed - nobody
 * learns about other organisers' sections.
 */
final class PageSectionNotFound extends NotFoundHttpException
{
}
