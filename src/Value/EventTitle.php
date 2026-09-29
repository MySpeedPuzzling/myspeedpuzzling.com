<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\EditionRoundDetail;

/**
 * How an event - a standalone competition or an edition of a series - is named in the page titles and
 * meta descriptions of its event, edition and round results pages.
 *
 * - The name is never shortened: it is exactly what people search. An edition whose name does not
 *   mention its series gets the series in front ("Piece-off · #21 - May 2026"), because edition names
 *   alone are often meaningless ("#21 - May 2026", "2026 Finals").
 * - The year follows the name unless the name already carries one ("Puzzle Marathon 2026").
 * - The event is over once its last day is before today - date_to ?? date_from, compared by calendar
 *   day like CompetitionEvent::startsAfter() and the event lists. Its pages then say "Results" - but
 *   only when MySpeedPuzzling has results for it (saysResults()).
 *
 * Editions often have no dates of their own, only rounds - the round schedule stands in for them.
 */
readonly final class EventTitle
{
    // A standalone 4-digit year ("2026", "PJM2026") - not "1000" in "Teams 1000", nor digits of a longer number
    private const string YEAR_PATTERN = '/(?<!\d)(?:19|20)\d{2}(?!\d)/';

    private function __construct(
        public string $name,
        public null|string $year,
        public null|DateTimeImmutable $startsAt,
        public bool $isPast,
    ) {
    }

    /**
     * @param null|string $seriesName the series of an edition, null for a standalone competition
     * @param array<EditionRoundDetail> $rounds the competition's rounds, any order
     */
    public static function forCompetition(
        CompetitionEvent $competition,
        null|string $seriesName,
        array $rounds,
        DateTimeImmutable $today,
    ): self {
        $name = $competition->name;

        if ($seriesName !== null && mb_stripos($name, $seriesName) === false) {
            $name = $seriesName . ' · ' . $name;
        }

        $roundStarts = array_map(
            static fn (EditionRoundDetail $round): DateTimeImmutable => $round->startsAt,
            $rounds,
        );

        $startsAt = $competition->dateFrom ?? $competition->dateTo ?? ($roundStarts === [] ? null : min($roundStarts));
        $endsAt = $competition->dateTo ?? $competition->dateFrom ?? ($roundStarts === [] ? null : max($roundStarts));

        return new self(
            name: $name,
            year: $startsAt !== null && preg_match(self::YEAR_PATTERN, $name) !== 1 ? $startsAt->format('Y') : null,
            startsAt: $startsAt,
            isPast: $endsAt !== null && $endsAt->format('Y-m-d') < $today->format('Y-m-d'),
        );
    }

    /**
     * The name followed by the year when the name does not carry one - "Puzzle Marathon 2026".
     */
    public function label(): string
    {
        return $this->year === null ? $this->name : $this->name . ' ' . $this->year;
    }

    /**
     * Whether the title and meta description lead with "Results": the event is over and people added
     * results for it here. A "Results" title over a page without any would disappoint whoever searched
     * for them - such an event is named like an upcoming one.
     */
    public function saysResults(int $resultsCount): bool
    {
        return $this->isPast && $resultsCount > 0;
    }
}
