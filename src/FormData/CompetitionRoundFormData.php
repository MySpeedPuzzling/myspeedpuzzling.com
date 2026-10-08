<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;
use SpeedPuzzling\Web\Value\RoundBadgeColor;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
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
        // Null = picked automatically (RoundBadgeColor) - anything else must be a colour, not silently "automatic"
        #[Assert\Length(max: 250)]
        #[Assert\Regex(pattern: '/^\s*#?([0-9a-f]{3}|[0-9a-f]{6})\s*$/i', message: 'competition_round_badge_color_invalid')]
        public null|string $badgeBackgroundColor = null,
        // Not on the web form (the text colour is picked for contrast) - only the internal API sets it
        #[Assert\Length(max: 250)]
        public null|string $badgeTextColor = null,
        public RoundCategory $category = RoundCategory::Solo,
        #[Assert\Url]
        #[Assert\Length(max: 2000)]
        public null|string $resultsLink = null,
        // Minutes after the start when the round's secret puzzles with an automatic reveal come out (RoundPuzzleReveal)
        #[Assert\NotNull]
        #[Assert\Range(notInRangeMessage: 'competition_round_reveal_delay_range', min: 0, max: RoundPuzzleReveal::MAX_DELAY_MINUTES)]
        public null|int $revealDelayMinutes = RoundPuzzleReveal::DEFAULT_DELAY_MINUTES,
        // How many people a team of a team round is expected to have (CompetitionRound::$teamSize) - a hint for the
        // participants sheet, never a limit. Null = not set. Checked for team rounds only: the handlers ignore it for
        // solo and pair rounds (a round changed to one loses it), and a number left in the field hidden for another
        // category must not keep the form from saving
        #[Assert\When(
            expression: 'this.category.value === "team"',
            constraints: [new Assert\Range(min: CompetitionRound::TEAM_SIZE_MIN, max: CompetitionRound::TEAM_SIZE_MAX)],
        )]
        public null|int $teamSize = null,
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
        $data->revealDelayMinutes = $round->revealDelayMinutes;
        // The stored size only - a guess is the field's placeholder (CompetitionRoundFormType `team_size_guess`), so an
        // untouched form stores nothing
        $data->teamSize = $round->teamSize;

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
