<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\SeriesConversionBlocker;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A one-time event that cannot become the series itself (ConvertCompetitionToSeries with `keepAsEdition: false`): its
 * competition row is deleted, so anything of it that would be lost refuses the conversion - nothing changes
 * (docs/features/events-page/high-frequency-series.md "The conversion tool").
 */
final class CompetitionNotConvertible extends ConflictHttpException
{
    /**
     * @param list<SeriesConversionBlocker> $blockers
     */
    public function __construct(
        readonly public array $blockers,
    ) {
        $reasons = array_map(static fn (SeriesConversionBlocker $blocker): string => $blocker->value, $blockers);

        parent::__construct(sprintf(
            'The event cannot become the series itself, it has: %s.%s Convert it with "keepAsEdition": true instead (it becomes the first edition).',
            implode(', ', $reasons),
            $blockers === [SeriesConversionBlocker::Participants] ? ' Send "dropParticipants": true to delete its participants.' : '',
        ));
    }
}
