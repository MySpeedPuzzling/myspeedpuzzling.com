<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Turns a one-time event into a series (docs/features/events-page/high-frequency-series.md "The conversion tool").
 * `keepAsEdition: true` (the web button): the event becomes the series' first edition, its times stay on it.
 * `keepAsEdition: false` (internal API): the event becomes the series - its times become series picks of it, the
 * competition row goes, its old address leads to the series; refused while anything of the event would be lost
 * (CompetitionNotConvertible) - its participants only without `dropParticipants`.
 *
 * Under the event's participants lock: a registration never lands half way through, and the participants deleted with
 * `dropParticipants` are exactly the ones checked.
 */
readonly final class ConvertCompetitionToSeries implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public UuidInterface $seriesId,
        public bool $keepAsEdition = true,
        public bool $dropParticipants = false,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
