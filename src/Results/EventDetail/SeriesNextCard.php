<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventDetail;

use SpeedPuzzling\Web\Results\EventsPage\AgendaRow;

/**
 * The series page's Next card: the first live session, else the first upcoming one (never a long span). `row.when` is
 * null beyond 30 days - the template then writes the full date.
 */
readonly final class SeriesNextCard
{
    public function __construct(
        public AgendaRow $row,
        public string $competitionId,
        public bool $isGoing,
        public bool $registrationManaged,
        // external, only while registration is not managed here
        public null|string $registrationLink,
        public bool $isPublic,
    ) {
    }
}
