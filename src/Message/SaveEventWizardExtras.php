<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLocks;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The event creation wizard's "Extras" (W-creation-wizard-research.md §3.8) in one transaction - all or nothing: a row
 * refused (the display limit, a standing) rolls back every row written before it. An orchestration of the existing
 * messages, each only when its row changed, in this order:
 *
 * 1. $registration - ChangeCompetitionRegistrationSettings;
 * 2. $links - EditCompetition with the registration/results links elsewhere, every other field as it is;
 * 3. $removeReferees / $addReferees - the judges (AddCompetitionReferee answers its outcome, returned by the handler);
 * 4. $competitorPages - ChangeCompetitorPageSettings (QR tags, competitor pages, time proposals);
 * 5. $displays - AddCompetitionDisplay per row (`timerScreen`, `overlay`), refused over the event's limit;
 * 6. $standings - CreateCompetitionStanding per category ("overall standings").
 *
 * Holds the event's participants lock and every other key its nested messages take (the referees', the displays' -
 * SerializedByLocks), until the commit.
 */
readonly final class SaveEventWizardExtras implements SerializedByLocks
{
    /**
     * @param list<RemoveCompetitionReferee> $removeReferees
     * @param list<AddCompetitionReferee> $addReferees
     * @param array<string, AddCompetitionDisplay> $displays the Extras row (form field) => its display
     * @param list<CreateCompetitionStanding> $standings
     */
    public function __construct(
        public string $competitionId,
        public null|ChangeCompetitionRegistrationSettings $registration = null,
        public null|EditCompetition $links = null,
        public array $removeReferees = [],
        public array $addReferees = [],
        public null|ChangeCompetitorPageSettings $competitorPages = null,
        public array $displays = [],
        public array $standings = [],
    ) {
        $competitionId = strtolower($competitionId);
        $nested = [$registration, $links, ...$removeReferees, ...$addReferees, $competitorPages, ...array_values($displays), ...$standings];

        foreach ($nested as $message) {
            if ($message !== null && strtolower($message->competitionId) !== $competitionId) {
                throw new \InvalidArgumentException('Every row must belong to the event locked.');
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->registration === null
            && $this->links === null
            && $this->removeReferees === []
            && $this->addReferees === []
            && $this->competitorPages === null
            && $this->displays === []
            && $this->standings === [];
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }

    public function moreLockKeys(): array
    {
        return array_values(array_unique(array_map(
            static fn (AddCompetitionReferee|AddCompetitionDisplay $message): string => $message->lockKey(),
            [...$this->addReferees, ...array_values($this->displays)],
        )));
    }
}
