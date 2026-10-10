<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A period key that is not the canonical key of a week, a two-week period or a month (DigestPeriod::fromKey()) -
 * a 404 on the "Your week" page.
 */
final class InvalidDigestPeriod extends NotFoundHttpException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('Invalid digest period "%s".', $key));
    }
}
