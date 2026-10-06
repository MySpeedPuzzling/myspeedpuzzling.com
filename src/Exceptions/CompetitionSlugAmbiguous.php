<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Several editions (of different series) share the slug asked for - an edition's slug is unique only within its series.
 */
final class CompetitionSlugAmbiguous extends ConflictHttpException
{
    /**
     * @param list<string> $competitionIds
     */
    public function __construct(string $slug, array $competitionIds)
    {
        parent::__construct(sprintf(
            'Several editions use the slug "%s" - ask by id: %s.',
            $slug,
            implode(', ', $competitionIds),
        ));
    }
}
