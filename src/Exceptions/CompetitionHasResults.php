<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CompetitionHasResults extends ConflictHttpException
{
    public function __construct(
        readonly public int $resultsCount,
    ) {
        parent::__construct(sprintf(
            'The competition has %d result(s) - it is not deleted. Remove it in the event management if it really has to go.',
            $resultsCount,
        ));
    }
}
