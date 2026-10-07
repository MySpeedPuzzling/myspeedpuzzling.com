<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;
use SpeedPuzzling\Web\Value\RoundBadgeColor;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Component\Validator\Constraints as Assert;

final class CompetitionRoundFormData
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 250)]
        public null|string $name = null,
        #[Assert\Positive]
        public null|int $minutesLimit = null,
        // Multi-day event: the local date and time in $timezone, carried as a wall clock (see fromCompetitionRound)
        public null|DateTimeImmutable $startsAt = null,
        // Single-day event: the local "H:i" in $timezone on the event's day
        public null|string $startsAtTime = null,
        public null|string $timezone = null,
        // Null = picked automatically (RoundBadgeColor)
        #[Assert\Length(max: 250)]
        public null|string $badgeBackgroundColor = null,
        // Not on the web form (the text colour is picked for contrast) - only the internal API sets it
        #[Assert\Length(max: 250)]
        public null|string $badgeTextColor = null,
        public RoundCategory $category = RoundCategory::Solo,
        #[Assert\Url]
        #[Assert\Length(max: 2000)]
        public null|string $resultsLink = null,
    ) {
    }

    public static function forNewRound(string $timezone): self
    {
        $data = new self();
        $data->timezone = $timezone;

        return $data;
    }

    public static function fromCompetitionRound(CompetitionRound $round): self
    {
        $timezone = $round->displayTimezone();
        $localStart = RoundTimezone::toLocal($round->startsAt, $timezone);

        $data = new self();
        $data->name = $round->name;
        $data->minutesLimit = $round->minutesLimit;
        $data->timezone = $timezone;
        // DateTimeType shows a value in the server's zone - hand it the local wall clock in that zone, untouched
        $data->startsAt = new DateTimeImmutable($localStart->format('Y-m-d H:i:s'));
        $data->startsAtTime = $localStart->format('H:i');
        // The old form default and garbage mean "automatic" - the form shows them as no colour (RoundBadgeColor::chosen())
        $data->badgeBackgroundColor = RoundBadgeColor::chosen($round->badgeBackgroundColor);
        $data->badgeTextColor = $round->badgeTextColor;
        $data->category = $round->category;
        $data->resultsLink = $round->resultsLink;

        return $data;
    }

    /**
     * The instant the organiser means: the typed local time in the chosen zone.
     *
     * @param null|DateTimeImmutable $eventDay the day of a single-day event (only the time is typed), null otherwise
     *
     * @throws InvalidLocalTime the typed time does not exist exactly once in the zone (a daylight-saving change)
     */
    public function startsAtInstant(null|DateTimeImmutable $eventDay): DateTimeImmutable
    {
        assert($this->timezone !== null);

        if ($eventDay !== null) {
            assert($this->startsAtTime !== null);
            [$hours, $minutes] = array_map(intval(...), explode(':', $this->startsAtTime) + [1 => '0']);

            return RoundTimezone::toInstant(
                sprintf('%s %02d:%02d', $eventDay->format('Y-m-d'), $hours, $minutes),
                $this->timezone,
            );
        }

        assert($this->startsAt !== null);

        return RoundTimezone::toInstant($this->startsAt->format('Y-m-d H:i'), $this->timezone);
    }
}
