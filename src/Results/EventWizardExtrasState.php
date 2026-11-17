<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CompetitorPageSettings;

/**
 * What the event has now of the features the wizard's Extras step switches (EventWizardExtras) - the switches start
 * from it, Review lists what is on.
 */
readonly final class EventWizardExtrasState
{
    /**
     * @param list<CompetitionRefereeListItem> $referees
     */
    public function __construct(
        public string $competitionId,
        public bool $isOnline,
        public bool $registrationManaged,
        public null|int $capacity,
        public null|DateTimeImmutable $registrationOpensAt,
        public null|DateTimeImmutable $registrationClosesAt,
        // The zone the window is typed in: the saved one, else the zone of the event's rounds, else of its country
        public string $registrationTimezone,
        public null|string $entryFeeText,
        public null|string $paymentInstructions,
        public null|string $registrationLink,
        public null|string $resultsLink,
        public array $referees,
        public CompetitorPageSettings $competitorPageSettings,
        public int $screensCount,
        public int $overlaysCount,
        public int $standingsCount,
        public int $roundsCount,
        // Whom COMPETITION_TOOLKIT_MANAGE grants - the toolkit rows exist only for them
        public bool $toolkit,
    ) {
    }

    /**
     * Name tags with QR codes and competitor pages are for events in person (the toolkit hub's rule)
     */
    public function competitorPagesOffered(): bool
    {
        return $this->toolkit && $this->isOnline === false;
    }

    /**
     * A timer screen for the hall - in person only; once the event has one it is managed on the displays page
     */
    public function timerScreenOffered(): bool
    {
        return $this->toolkit && $this->isOnline === false && $this->screensCount === 0;
    }

    public function overlayOffered(): bool
    {
        return $this->toolkit && $this->overlaysCount === 0;
    }

    /**
     * Overall standings sum several rounds - never asked of one round; once the event has standings (Structure made
     * them, or the standings page) they are managed there
     */
    public function standingsOffered(): bool
    {
        return $this->toolkit && $this->roundsCount >= 2 && $this->standingsCount === 0;
    }

    public function standingsShown(): bool
    {
        return $this->toolkit && $this->roundsCount >= 2;
    }
}
