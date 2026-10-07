<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Entity\CompetitionPageSection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The page has CompetitionPageSection::MAX_PER_PAGE sections already (visible and hidden together).
 */
final class PageSectionLimitReached extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct(sprintf('A page can have at most %d sections', CompetitionPageSection::MAX_PER_PAGE));
    }
}
