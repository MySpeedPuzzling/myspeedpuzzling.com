<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Results\RoundResultChangeOutcome;
use SpeedPuzzling\Web\Value\NewRoundEntry;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundResultChange;
use SpeedPuzzling\Web\Value\RoundResultChangeStatus;
use SpeedPuzzling\Web\Value\RoundResultField;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Plans the whole change set first - every change checked three-way against the working state of the round, table
 * numbers unique within the round after the whole set (so a swap sent as two changes works) - and only then writes,
 * so a refused change never leaves half of a set behind (docs/features/competitions-management/official-results.md).
 *
 * @phpstan-type EntryState array{result: RoundEntryResult, table_number: null|int, qualified: bool, enteredAt: null|DateTimeImmutable, enteredById: null|string, enteredByName: null|string}
 * @phpstan-type Plan array{outcomes: list<RoundResultChangeOutcome>, applied: list<int>, tableChanges: array<string, list<int>>, newEntries: array<string, NewRoundEntry>, state: array<string, EntryState>}
 */
#[AsMessageHandler]
readonly final class RecordRoundResultsHandler
{
    public const int NAME_MAX_LENGTH = 255;
    public const int MAX_TEAM_MEMBERS = 20;
    public const int MAX_TABLE_NUMBER = 9999;
    // A round without exactly one puzzle has no piece count to check against - this only stops a typo of a different kind
    public const int MAX_PIECES_PLACED = 100000;

    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $teamRepository,
        private CompetitionParticipantRepository $participantRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound when the round is not one of the authorised competition
     */
    public function __invoke(RecordRoundResults $message): RecordedRoundResults
    {
        $round = $this->roundRepository->get($message->roundId);

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        $actor = $this->playerRepository->get($message->actingPlayerId);
        $now = $this->clock->now();
        $entries = $this->entriesOf($round);

        // Changes refused because their table number would be shared - planning again without them may free a number
        // somebody else takes back, so until nothing new is refused
        $excluded = [];
        do {
            $plan = $this->plan($message, $round, $entries, $excluded, $actor, $now);
            $clashes = $this->tableNumberClashes($plan, $excluded);
            $excluded += $clashes;
        } while ($clashes !== []);

        if ($message->dryRun) {
            return new RecordedRoundResults($plan['outcomes'], [], true);
        }

        $changed = [];

        foreach ($plan['newEntries'] as $ref => $newEntry) {
            $entries[$ref] = $this->create($newEntry, $round);
            $changed[$ref] = true;
        }

        foreach ($plan['applied'] as $index) {
            $change = $message->changes[$index];
            $ref = $change->entryRef()?->toString();
            assert($ref !== null && isset($entries[$ref]));
            $entry = $entries[$ref];

            match ($change->field) {
                RoundResultField::Result => $entry->recordResult(self::result($change->to), $actor, $now),
                RoundResultField::TableNumber => $entry->assignTableNumber(self::tableNumber($change->to)),
                RoundResultField::Qualified => $change->to === true ? $entry->markQualified($now) : $entry->unmarkQualified(),
                null => throw new \LogicException('An applied change has a field.'),
            };

            $changed[$ref] = true;
        }

        return new RecordedRoundResults($plan['outcomes'], array_keys($changed), false);
    }

    /**
     * @return array<string, CompetitionParticipantRound|CompetitionTeam> ref => entry; people removed from the event left out
     */
    private function entriesOf(CompetitionRound $round): array
    {
        $entries = [];

        if ($round->category === RoundCategory::Solo) {
            foreach ($this->participantRoundRepository->findByRound($round) as $participantRound) {
                if ($participantRound->participant->isDeleted() === false) {
                    $entries[$participantRound->entryRef()->toString()] = $participantRound;
                }
            }

            return $entries;
        }

        foreach ($this->teamRepository->findByRound($round) as $team) {
            $entries[$team->entryRef()->toString()] = $team;
        }

        return $entries;
    }

    /**
     * @param array<string, CompetitionParticipantRound|CompetitionTeam> $entries
     * @param array<int, string> $excluded change index => reason
     * @return Plan
     */
    private function plan(
        RecordRoundResults $message,
        CompetitionRound $round,
        array $entries,
        array $excluded,
        Player $actor,
        DateTimeImmutable $now,
    ): array {
        /** @var array<string, EntryState> $state */
        $state = [];
        foreach ($entries as $ref => $entry) {
            $state[$ref] = [
                'result' => $entry->officialResult(),
                'table_number' => $entry->tableNumber,
                'qualified' => $entry->isQualified(),
                'enteredAt' => $entry->resultEnteredAt,
                'enteredById' => $entry->resultEnteredBy?->id->toString(),
                'enteredByName' => $entry->resultEnteredBy !== null ? ($entry->resultEnteredBy->name ?? '#' . strtoupper($entry->resultEnteredBy->code)) : null,
            ];
        }

        $outcomes = [];
        $applied = [];
        $tableChanges = [];
        /** @var array<string, NewRoundEntry> $newEntries entries this set creates */
        $newEntries = [];
        /** @var array<string, NewRoundEntry> $pendingNew new entries seen, created only when one of their changes goes through */
        $pendingNew = [];

        foreach ($message->changes as $index => $change) {
            $ref = $change->entryRef();
            $refString = $ref?->toString();

            if ($change->rejectedReason !== null || $change->field === null || $refString === null) {
                $outcomes[] = self::outcome($change, RoundResultChangeStatus::Rejected, $change->rejectedReason ?? 'invalid_change', null);

                continue;
            }

            if (!isset($state[$refString])) {
                if ($change->newEntry === null) {
                    $outcomes[] = self::outcome($change, RoundResultChangeStatus::Rejected, 'entry_not_found', null);

                    continue;
                }

                $refusal = $this->newEntryRefusal($change->newEntry, $round);

                if ($refusal !== null) {
                    $outcomes[] = self::outcome($change, RoundResultChangeStatus::Rejected, $refusal, null);

                    continue;
                }

                $state[$refString] = [
                    'result' => RoundEntryResult::none(),
                    'table_number' => null,
                    'qualified' => false,
                    'enteredAt' => null,
                    'enteredById' => null,
                    'enteredByName' => null,
                ];
                $pendingNew[$refString] = $change->newEntry;
            }

            $current = self::value($state[$refString], $change->field);
            $refusal = $excluded[$index] ?? ($message->resultsOnly && $change->field !== RoundResultField::Result ? 'results_only' : null) ?? self::valueRefusal($change, $round);

            if ($refusal !== null) {
                $outcomes[] = self::outcome($change, RoundResultChangeStatus::Rejected, $refusal, $current, $state[$refString]);

                continue;
            }

            if (self::same($change->field, $current, $change->to)) {
                $outcomes[] = self::outcome($change, RoundResultChangeStatus::Unchanged, null, $current, $state[$refString]);
            } elseif (self::same($change->field, $current, $change->from)) {
                $state[$refString] = self::withValue($state[$refString], $change->field, $change->to, $actor, $now);
                $applied[] = $index;

                if ($change->field === RoundResultField::TableNumber) {
                    $tableChanges[$refString][] = $index;
                }

                $outcomes[] = self::outcome($change, RoundResultChangeStatus::Applied, null, $change->to, $state[$refString]);
            } else {
                $outcomes[] = self::outcome($change, RoundResultChangeStatus::Conflict, 'changed_meanwhile', $current, $state[$refString]);

                continue;
            }

            if (isset($pendingNew[$refString])) {
                $newEntries[$refString] = $pendingNew[$refString];
            }
        }

        return [
            'outcomes' => $outcomes,
            'applied' => $applied,
            'tableChanges' => $tableChanges,
            'newEntries' => $newEntries,
            'state' => $state,
        ];
    }

    /**
     * Table numbers the change set would give to two entries of the round: every change that set one of them is
     * refused. A number two entries share already (older data) refuses nothing.
     *
     * @param Plan $plan
     * @param array<int, string> $excluded
     * @return array<int, string> change index => reason, only changes not excluded yet
     */
    private function tableNumberClashes(array $plan, array $excluded): array
    {
        /** @var array<int, list<string>> $refsByNumber */
        $refsByNumber = [];
        foreach ($plan['state'] as $ref => $entryState) {
            if ($entryState['table_number'] !== null) {
                $refsByNumber[$entryState['table_number']][] = $ref;
            }
        }

        $clashes = [];
        foreach ($refsByNumber as $refs) {
            if (count($refs) < 2) {
                continue;
            }

            foreach ($refs as $ref) {
                foreach ($plan['tableChanges'][$ref] ?? [] as $index) {
                    if (!isset($excluded[$index])) {
                        $clashes[$index] = 'table_number_taken';
                    }
                }
            }
        }

        return $clashes;
    }

    private function newEntryRefusal(NewRoundEntry $newEntry, CompetitionRound $round): null|string
    {
        $expectedKind = $round->category === RoundCategory::Solo ? NewRoundEntry::KIND_PERSON : NewRoundEntry::KIND_TEAM;

        if ($newEntry->kind !== $expectedKind) {
            return 'entry_kind_mismatch';
        }

        // The device's id is the entry's id - one used by anything else is refused, never taken over
        $taken = $newEntry->kind === NewRoundEntry::KIND_PERSON
            ? $this->participantRoundRepository->find($newEntry->clientEntryId) !== null
            : $this->teamRepository->find($newEntry->clientEntryId) !== null;

        if ($taken) {
            return 'entry_id_taken';
        }

        $names = array_column($newEntry->members, 'name');

        if ($newEntry->name !== null) {
            $names[] = $newEntry->name;
        }

        foreach ($names as $name) {
            if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
                return 'entry_name_too_long';
            }
        }

        if ($newEntry->kind === NewRoundEntry::KIND_PERSON && $newEntry->name === null) {
            return 'entry_name_missing';
        }

        if ($newEntry->kind === NewRoundEntry::KIND_TEAM && $newEntry->name === null && $newEntry->members === []) {
            return 'entry_name_missing';
        }

        if (count($newEntry->members) > self::MAX_TEAM_MEMBERS) {
            return 'too_many_members';
        }

        return null;
    }

    private static function valueRefusal(RoundResultChange $change, CompetitionRound $round): null|string
    {
        if ($change->field === RoundResultField::TableNumber) {
            $tableNumber = self::tableNumber($change->to);

            return $tableNumber !== null && ($tableNumber < 1 || $tableNumber > self::MAX_TABLE_NUMBER) ? 'invalid_table_number' : null;
        }

        if ($change->field !== RoundResultField::Result) {
            return null;
        }

        $result = self::result($change->to);

        if ($result->seconds !== null && ($result->seconds < 1 || $result->seconds > RoundEntryResult::MAX_SECONDS)) {
            return 'invalid_seconds';
        }

        if ($result->piecesPlaced !== null) {
            $piecesCount = $round->singlePuzzlePiecesCount();
            $maximum = $piecesCount !== null ? $piecesCount - 1 : self::MAX_PIECES_PLACED;

            if ($result->piecesPlaced < 1 || $result->piecesPlaced > $maximum) {
                return 'invalid_pieces_placed';
            }
        }

        return null;
    }

    private function create(NewRoundEntry $newEntry, CompetitionRound $round): CompetitionParticipantRound|CompetitionTeam
    {
        if ($newEntry->kind === NewRoundEntry::KIND_PERSON) {
            assert($newEntry->name !== null);
            $participant = $this->newParticipant($newEntry->name, $newEntry->country, $round);
            $participantRound = new CompetitionParticipantRound(Uuid::fromString($newEntry->clientEntryId), $participant, $round);
            $this->participantRoundRepository->save($participantRound);

            return $participantRound;
        }

        $team = new CompetitionTeam(Uuid::fromString($newEntry->clientEntryId), $round, $newEntry->name);
        $this->teamRepository->save($team);

        foreach ($newEntry->members as $member) {
            $participant = $this->newParticipant($member['name'], $member['country'], $round);
            $this->participantRoundRepository->save(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round, $team));
        }

        return $team;
    }

    private function newParticipant(string $name, null|string $country, CompetitionRound $round): CompetitionParticipant
    {
        $participant = new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: $name,
            country: $country,
            competition: $round->competition,
            source: ParticipantSource::Manual,
        );
        $this->participantRepository->save($participant);

        return $participant;
    }

    /**
     * @param EntryState $entryState
     */
    private static function value(array $entryState, RoundResultField $field): null|RoundEntryResult|int|bool
    {
        return match ($field) {
            RoundResultField::Result => $entryState['result'],
            RoundResultField::TableNumber => $entryState['table_number'],
            RoundResultField::Qualified => $entryState['qualified'],
        };
    }

    /**
     * @param EntryState $entryState
     * @return EntryState
     */
    private static function withValue(array $entryState, RoundResultField $field, null|RoundEntryResult|int|bool $value, Player $actor, DateTimeImmutable $now): array
    {
        if ($field === RoundResultField::Result) {
            $entryState['result'] = self::result($value);
            $entryState['enteredAt'] = $now;
            $entryState['enteredById'] = $actor->id->toString();
            $entryState['enteredByName'] = $actor->name ?? '#' . strtoupper($actor->code);
        } elseif ($field === RoundResultField::TableNumber) {
            $entryState['table_number'] = self::tableNumber($value);
        } else {
            $entryState['qualified'] = $value === true;
        }

        return $entryState;
    }

    private static function same(RoundResultField $field, null|RoundEntryResult|int|bool $a, null|RoundEntryResult|int|bool $b): bool
    {
        return match ($field) {
            RoundResultField::Result => self::result($a)->equals(self::result($b)),
            RoundResultField::TableNumber => self::tableNumber($a) === self::tableNumber($b),
            RoundResultField::Qualified => ($a === true) === ($b === true),
        };
    }

    private static function result(null|RoundEntryResult|int|bool $value): RoundEntryResult
    {
        return $value instanceof RoundEntryResult ? $value : RoundEntryResult::none();
    }

    private static function tableNumber(null|RoundEntryResult|int|bool $value): null|int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * @param null|EntryState $entryState
     */
    private static function outcome(
        RoundResultChange $change,
        RoundResultChangeStatus $status,
        null|string $reason,
        null|RoundEntryResult|int|bool $current,
        null|array $entryState = null,
    ): RoundResultChangeOutcome {
        $withEntered = $entryState !== null && $change->field === RoundResultField::Result;

        return new RoundResultChangeOutcome(
            clientChangeId: $change->clientChangeId,
            status: $status,
            reason: $reason,
            entryRef: $change->entryRef()?->toString(),
            field: $change->field,
            current: $current,
            enteredAt: $withEntered ? $entryState['enteredAt'] : null,
            enteredById: $withEntered ? $entryState['enteredById'] : null,
            enteredByName: $withEntered ? $entryState['enteredByName'] : null,
        );
    }
}
