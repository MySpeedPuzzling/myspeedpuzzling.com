<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An explicitly chosen slug another competition already holds (CompetitionSlugGenerator::isTaken()) - or, for a
 * series, another series (isSeriesSlugTaken()).
 */
final class CompetitionSlugTaken extends ConflictHttpException
{
    public function __construct(
        readonly public string $slug,
    ) {
        parent::__construct(sprintf('The slug "%s" is already used by another competition.', $slug));
    }
}
