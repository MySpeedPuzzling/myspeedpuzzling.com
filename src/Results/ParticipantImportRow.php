<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * One row of the uploaded file in the import preview.
 */
readonly final class ParticipantImportRow
{
    /**
     * @param list<array{field: string, before: null|string, after: null|string}> $changes field = translation key suffix
     *        of `competition.participants.import.field.*` (name, country, external_id, msp_player_id)
     * @param list<string> $roundsAdded round names the row adds the person to
     * @param list<string> $roundsRemoved round names full sync takes the person out of (only on the person's first row)
     * @param array<string, null|string> $teams round name => team name after the import, for the row's pair/team rounds
     *        (null = no team in that round)
     * @param array<string, null|string> $teamsBefore round name => team name before the import, only for the rounds of
     *        $teams whose team the import gives or changes (null = no team, or an unnamed one)
     * @param list<TranslatableMessage> $messages what the organiser should know about this row
     * @param list<string> $roundsRestored round names a restored participant comes back with (Restore only; in full sync
     *        after the file's rounds took out what it does not list)
     * @param array<string, array{before: null|string, after: string}> $divisions round name => the division the row gives
     *        the entry (before = the one it had, null = none) - only rounds whose division the import changes
     * @param list<string> $teamRounds a team row's rounds (round names) - shown once above its people
     */
    public function __construct(
        public int $rowNumber,
        public string $name,
        public ParticipantImportRowAction $action,
        /** The matched participant, null for a new one or a skipped row */
        public null|string $participantId = null,
        public array $changes = [],
        public array $roundsAdded = [],
        public array $roundsRemoved = [],
        public array $teams = [],
        public array $messages = [],
        /** When a removed participant is restored: when they were removed */
        public null|\DateTimeImmutable $removedAt = null,
        public array $roundsRestored = [],
        public array $teamsBefore = [],
        /** The MySpeedPuzzling profile line - when the file names a player or the import links one */
        public null|ParticipantImportRowProfile $profile = null,
        public array $divisions = [],
        /** The file's row of the team row this person comes from (member columns mapped) - null = a row per person */
        public null|int $teamRow = null,
        /** The member column of that team row (1 = the first) */
        public null|int $member = null,
        /** The team row's pair/team in its pair/team rounds - its Team cell or "Team of <first member>" */
        public null|string $teamName = null,
        public array $teamRounds = [],
    ) {
    }

    /**
     * The row number the organiser sees in the file - a team row's for its people.
     */
    public function fileRow(): int
    {
        return $this->teamRow ?? $this->rowNumber;
    }

    public function hasWarning(): bool
    {
        return $this->messages !== [];
    }
}
