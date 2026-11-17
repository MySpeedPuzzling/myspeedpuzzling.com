<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\EventTypes;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Takes turns with every write to the event's participants (CompetitionParticipantsLock): moving the rounds with the
 * date (moveRoundsWithDate) checks that no round has an official result under the same lock the results desk records
 * under.
 */
readonly final class EditCompetition implements SerializedByLock
{
    /**
     * @param array<string> $maintainerIds
     */
    public function __construct(
        public string $competitionId,
        public string $name,
        public null|string $shortcut,
        public null|string $description,
        public null|string $link,
        public null|string $registrationLink,
        public null|string $resultsLink,
        public null|string $location,
        public null|string $locationCountryCode,
        public null|DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public bool $isOnline,
        public null|UploadedFile $logo,
        public array $maintainerIds,
        // "Who can enter" (docs/features/organizations/README.md) - an edition without one shows its series'
        public null|string $eligibility,
        // What kind of event (EventTypes) - null = not set, an edition's equal to its series' follows the series
        public null|EventTypes $eventTypes,
        // An explicitly chosen slug (validated unique) - null keeps the slug, also on a rename: published links and
        // search engines know the competition by it
        public null|string $slug = null,
        // A new first day (dateFrom) moves every round's start and the registration window by the same number of days,
        // keeping their times of day (EventDateShift - the rule of a copy with a new date) - only while no round has
        // begun (EventDateShift::roundsCanMove()). False = the rounds stay where they are (the internal API, every other
        // caller)
        public bool $moveRoundsWithDate = false,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
