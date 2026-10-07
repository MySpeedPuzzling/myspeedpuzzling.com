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
use SpeedPuzzling\Web\Entity\RoundResultChangeReceipt;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\RoundResultChangeReceiptRepository;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Results\RoundResultChangeOutcome;
use SpeedPuzzling\Web\Value\NewRoundEntry;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundResultChange;
use SpeedPuzzling\Web\Value\RoundResultChangeStatus;
use SpeedPuzzling\Web\Value\RoundResultField;
use SpeedPuzzling\Web\Events\OfficialRoundResultsPublished;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Plans the whole change set first - every change checked three-way against the working state of the round, table
 * numbers unique within the round after the whole set (so a swap sent as two changes works) - and only then writes,
 * so a refused change never leaves half of a set behind (docs/features/competitions-management/official-results.md).
 * A change whose id the server took before (RoundResultChangeReceipt) is answered "unchanged" and never applied
 * again; every change that goes through, or finds its value there already, leaves such a receipt.
 *
 * @phpstan-type EntryState array{result: RoundEntryResult, table_number: null|int, qualified: bool, enteredAt: null|DateTimeImmutable, enteredById: null|string, enteredByName: null|string}
 * @phpstan-type Plan array{outcomes: list<RoundResultChangeOutcome>, applied: list<int>, tableChanges: array<string, list<int>>, newEntries: array<string, NewRoundEntry>, state: array<string, EntryState>}
 * @phpstan-type Context array{round: CompetitionRound, receipts: array<string, RoundResultChangeReceipt>, rowsByParticipant: array<string, CompetitionParticipantRound>}
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
        private RoundResultChangeReceiptRepository $receiptRepository,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
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
        $roundRows = $this->participantRoundRepository->findByRound($round);
        $entries = $this->entriesOf($round, $roundRows);
        $receipts = $this->receiptRepository->findByIds(array_map(
            static fn (RoundResultChange $change): string => $change->clientChangeId,
            $message->changes,
        ));
        $context = [
            'round' => $round,
            'receipts' => $receipts,
            'rowsByParticipant' => self::rowsByParticipant($roundRows),
        ];

        // Changes refused because their table number would be shared - planning again without them may free a number
        // somebody else takes back, so until nothing new is refused
        $excluded = [];
        do {
            $plan = $this->plan($message, $context, $entries, $excluded, $actor, $now);
            $clashes = $this->tableNumberClashes($plan, $excluded);
            $excluded += $clashes;
        } while ($clashes !== []);

        if ($message->dryRun) {
            return new RecordedRoundResults($plan['outcomes'], [], true);
        }

        $changed = [];

        foreach ($plan['newEntries'] as $ref => $newEntry) {
            $entries[$ref] = $this->create($newEntry, $round, $context['rowsByParticipant']);
            $changed[$ref] = true;
        }

        $finishedResultRecorded = false;

        foreach ($plan['applied'] as $index) {
            $change = $message->changes[$index];
            $ref = $change->entryRef()?->toString();
            assert($ref !== null && isset($entries[$ref]));
            $entry = $entries[$ref];

            if ($change->field === RoundResultField::Result && self::result($change->to)->isFinished()) {
                $finishedResultRecorded = true;
            }

            match ($change->field) {
                RoundResultField::Result => $entry->recordResult(self::result($change->to), $actor, $now),
                RoundResultField::TableNumber => $entry->assignTableNumber(self::tableNumber($change->to)),
                RoundResultField::Qualified => $change->to === true ? $entry->markQualified($now) : $entry->unmarkQualified(),
                null => throw new \LogicException('An applied change has a field.'),
            };

            $changed[$ref] = true;
        }

        // A finished result on published results may be news for somebody not told yet (a referee's phone syncing late, a
        // corrected did-not-finish): the notification runs again - once for the whole set, after the commit; players
        // told before are never told twice
        if ($finishedResultRecorded && $round->areResultsPublished()) {
            $this->messageBus->dispatch(new OfficialRoundResultsPublished($round->id), [new DispatchAfterCurrentBusStamp()]);
        }

        // The ids of everything that went through or was there already - sent again, they change nothing any more
        foreach ($plan['outcomes'] as $outcome) {
            $taken = $outcome->status === RoundResultChangeStatus::Applied || $outcome->status === RoundResultChangeStatus::Unchanged;

            if ($taken && !isset($receipts[$outcome->clientChangeId])) {
                $this->receiptRepository->save(new RoundResultChangeReceipt(Uuid::fromString($outcome->clientChangeId), $round, $outcome->status, $now));
            }
        }

        return new RecordedRoundResults($plan['outcomes'], array_keys($changed), false);
    }

    /**
     * @param list<CompetitionParticipantRound> $roundRows every person of the round
     * @return array<string, CompetitionParticipantRound|CompetitionTeam> ref => entry; only people going to the event
     *                                                                   (GetRoundResultEntries - removed ones and the waitlist left out)
     */
    private function entriesOf(CompetitionRound $round, array $roundRows): array
    {
        $entries = [];

        if ($round->category === RoundCategory::Solo) {
            foreach ($roundRows as $participantRound) {
                if ($participantRound->participant->isGoing()) {
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
     * @param list<CompetitionParticipantRound> $roundRows
     * @return array<string, CompetitionParticipantRound> participant id => their row in the round
     */
    private static function rowsByParticipant(array $roundRows): array
    {
        $rows = [];
        foreach ($roundRows as $row) {
            $rows[$row->participant->id->toString()] = $row;
        }

        return $rows;
    }

    /**
     * @param Context $context
     * @param array<string, CompetitionParticipantRound|CompetitionTeam> $entries
     * @param array<int, string> $excluded change index => reason
     * @return Plan
     */
    private function plan(
        RecordRoundResults $message,
        array $context,
        array $entries,
        array $excluded,
        Player $actor,
        DateTimeImmutable $now,
    ): array {
        $round = $context['round'];
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
        /** @var array<string, true> $claimed participants of the event the set's new entries put into the round */
        $claimed = [];

        foreach ($message->changes as $index => $change) {
            $ref = $change->entryRef();
            $refString = $ref?->toString();
            $receipt = $context['receipts'][$change->clientChangeId] ?? null;

            // Sent before and taken: a replay whose answer got lost - whatever the entry holds now stays
            if ($receipt !== null && $change->field !== null && $refString !== null) {
                if ($receipt->round->id->equals($round->id)) {
                    $current = isset($state[$refString]) ? self::value($state[$refString], $change->field) : null;
                    $outcomes[] = self::outcome($change, RoundResultChangeStatus::Unchanged, null, $current, $state[$refString] ?? null);
                } else {
                    $outcomes[] = self::outcome($change, RoundResultChangeStatus::Rejected, 'invalid_change', null);
                }

                continue;
            }

            if ($change->rejectedReason !== null || $change->field === null || $refString === null) {
                $outcomes[] = self::outcome($change, RoundResultChangeStatus::Rejected, $change->rejectedReason ?? 'invalid_change', null);

                continue;
            }

            if (!isset($state[$refString])) {
                if ($change->newEntry === null) {
                    $outcomes[] = self::outcome($change, RoundResultChangeStatus::Rejected, 'entry_not_found', null);

                    continue;
                }

                $refusal = $this->newEntryRefusal($change->newEntry, $round, $context['rowsByParticipant'], $claimed);

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

    /**
     * @param array<string, CompetitionParticipantRound> $rowsByParticipant
     * @param array<string, true> $claimed participants put into the round by earlier new entries of the set - this
     *                                     entry's are added when it is accepted
     */
    private function newEntryRefusal(NewRoundEntry $newEntry, CompetitionRound $round, array $rowsByParticipant, array &$claimed): null|string
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

        $names = array_filter(array_column($newEntry->members, 'name'), static fn (null|string $name): bool => $name !== null);

        if ($newEntry->name !== null) {
            $names[] = $newEntry->name;
        }

        foreach ($names as $name) {
            if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
                return 'entry_name_too_long';
            }
        }

        if ($newEntry->kind === NewRoundEntry::KIND_PERSON && $newEntry->name === null && $newEntry->participantId === null) {
            return 'entry_name_missing';
        }

        if ($newEntry->kind === NewRoundEntry::KIND_TEAM && $newEntry->name === null && $newEntry->members === []) {
            return 'entry_name_missing';
        }

        if (count($newEntry->members) > self::MAX_TEAM_MEMBERS) {
            return 'too_many_members';
        }

        $existing = $newEntry->existingParticipantIds();

        foreach ($existing as $participantId) {
            $refusal = $this->existingParticipantRefusal($participantId, $round, $rowsByParticipant);

            if ($refusal !== null) {
                return $refusal;
            }

            if (isset($claimed[$participantId]) || count(array_keys($existing, $participantId, true)) > 1) {
                return 'duplicate_entry';
            }
        }

        foreach ($existing as $participantId) {
            $claimed[$participantId] = true;
        }

        return null;
    }

    /**
     * A participant of the event put into the round by a new entry: one of this event, going (not removed, not waiting
     * on the waitlist - the organiser gives them a spot first), and not an entry of the round already - in a pair/team
     * round somebody of the round who is in no pair/team yet may join one.
     *
     * @param array<string, CompetitionParticipantRound> $rowsByParticipant
     */
    private function existingParticipantRefusal(string $participantId, CompetitionRound $round, array $rowsByParticipant): null|string
    {
        try {
            $participant = $this->participantRepository->getActiveOfCompetition($round->competition->id->toString(), $participantId);
        } catch (CompetitionParticipantNotFound) {
            return 'participant_not_found';
        }

        if ($participant->isGoing() === false) {
            return 'participant_waitlisted';
        }

        $row = $rowsByParticipant[$participantId] ?? null;

        if ($row !== null && ($round->category === RoundCategory::Solo || $row->team !== null)) {
            return 'participant_already_in_round';
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

    /**
     * @param array<string, CompetitionParticipantRound> $rowsByParticipant
     */
    private function create(NewRoundEntry $newEntry, CompetitionRound $round, array $rowsByParticipant): CompetitionParticipantRound|CompetitionTeam
    {
        $competitionId = $round->competition->id->toString();

        if ($newEntry->kind === NewRoundEntry::KIND_PERSON) {
            if ($newEntry->participantId !== null) {
                $participant = $this->participantRepository->getActiveOfCompetition($competitionId, $newEntry->participantId);
            } else {
                assert($newEntry->name !== null);
                $participant = $this->newParticipant($newEntry->name, $newEntry->country, $round);
            }

            $participantRound = new CompetitionParticipantRound(Uuid::fromString($newEntry->clientEntryId), $participant, $round);
            $this->participantRoundRepository->save($participantRound);

            return $participantRound;
        }

        $team = new CompetitionTeam(Uuid::fromString($newEntry->clientEntryId), $round, $newEntry->name);
        $this->teamRepository->save($team);

        foreach ($newEntry->members as $member) {
            if ($member['participantId'] !== null) {
                // In the round already without a pair/team (one row per person and round): that row joins this one
                $row = $rowsByParticipant[$member['participantId']] ?? null;

                if ($row !== null) {
                    $row->assignToTeam($team);

                    continue;
                }

                $participant = $this->participantRepository->getActiveOfCompetition($competitionId, $member['participantId']);
            } else {
                assert($member['name'] !== null);
                $participant = $this->newParticipant($member['name'], $member['country'], $round);
            }

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
