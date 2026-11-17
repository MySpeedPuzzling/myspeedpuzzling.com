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
use SpeedPuzzling\Web\Entity\CompetitionTimeProposal;
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
use SpeedPuzzling\Web\Services\CompetitionToolkit\PickRoundRecords;
use SpeedPuzzling\Web\Services\CompetitionToolkit\TimeProposalResolver;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
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
 * `pick` rounds (competition toolkit A3, A-round-formats.md §2.2-2.4): a `puzzles` change records the entry's
 * per-puzzle record, checked by RoundEntryPuzzles' rules - rules 1-5 per change, rule 6 (the record against the result)
 * on the entry as the whole set leaves it - and a `result` + `puzzles` change of one entry in one set are a group,
 * applied together or not at all (`group_refused`). Only a set with a `puzzles` change, or with a `result` change on a
 * `pick` round, reads the round's format and puzzles under a lock (PickRoundRecords); every other set runs exactly the
 * statements it ran before (RecordRoundResultsStatementPinTest).
 *
 * Time proposals (competition toolkit P1, R-review-and-plan.md §1.2): the round is read together with its competition
 * (one statement, as before), and only while the competition has `time_proposals` on does a set with a `result` or
 * `proposal` change read the round's proposals (TimeProposalResolver::load(), one statement) - a `result` change with
 * `proposalId` confirms that proposal, a `proposal` change rejects one (a judge's "Not right"), any other result
 * resolves the entry's pending proposal; all of it after the write, in the same transaction. With proposals off a
 * `proposal` change is refused (`proposals_off`) and nothing else differs.
 *
 * Finished with pieces missing (competition toolkit T6, official-results.md "Finished with pieces missing"): a
 * `missing_pieces` change sets how many pieces a finished puzzle lacked (a piece missing from the factory) - only on an
 * entry finished at that point of the set (`missing_pieces_not_finished`), 1..9999 and below the round's piece count
 * (`invalid_missing_pieces`). A `result` change to anything but a finished time drops the count; a finished time
 * corrected keeps it - so a device that never heard of the field cannot wipe it. A `result` + `missing_pieces` (or
 * `puzzles` + `missing_pieces`) change of one entry in one set are a group like `result` + `puzzles`.
 *
 * @phpstan-type EntryState array{result: RoundEntryResult, table_number: null|int, qualified: bool, puzzles: null|RoundEntryPuzzles, missing_pieces: null|int, enteredAt: null|DateTimeImmutable, enteredById: null|string, enteredByName: null|string}
 * @phpstan-type Plan array{outcomes: list<RoundResultChangeOutcome>, applied: list<int>, tableChanges: array<string, list<int>>, newEntries: array<string, NewRoundEntry>, state: array<string, EntryState>}
 * @phpstan-type Format null|array{toSolve: int, pieces: array<string, int>}
 * @phpstan-type Context array{round: CompetitionRound, receipts: array<string, RoundResultChangeReceipt>, rowsByParticipant: array<string, CompetitionParticipantRound>, format: Format, proposals: null|array<string, CompetitionTimeProposal>}
 */
#[AsMessageHandler]
readonly final class RecordRoundResultsHandler
{
    public const int NAME_MAX_LENGTH = 255;
    public const int MAX_TEAM_MEMBERS = 20;
    public const int MAX_TABLE_NUMBER = 9999;
    // A round without exactly one puzzle has no piece count to check against - this only stops a typo of a different kind
    public const int MAX_PIECES_PLACED = 100000;
    // Pieces missing from a finished puzzle (T6) - a factory gap is 1-3, this only keeps the number in its column
    public const int MAX_MISSING_PIECES = 9999;

    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $teamRepository,
        private CompetitionParticipantRepository $participantRepository,
        private PlayerRepository $playerRepository,
        private RoundResultChangeReceiptRepository $receiptRepository,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
        private PickRoundRecords $pickRoundRecords,
        private TimeProposalResolver $timeProposalResolver,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound when the round is not one of the authorised competition
     */
    public function __invoke(RecordRoundResults $message): RecordedRoundResults
    {
        // With its competition - whether it takes time proposals, without a statement of its own
        $round = $this->roundRepository->getWithCompetition($message->roundId);

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
            'format' => self::readsFormat($message, $round) ? $this->pickRoundRecords->lockForRecording($round->id->toString()) : null,
            // Competition toolkit P1 - nothing is read for a competition without time proposals
            'proposals' => $round->competition->timeProposals && TimeProposalResolver::concerns($message->changes)
                ? $this->timeProposalResolver->load($round, $message->changes)
                : null,
        ];

        // Changes refused because of the whole set - a table number it would give twice, a `pick` round's record that
        // disagrees with the result, the partner of a refused change of the same group: planning again without them may
        // free a number somebody else takes back, or refuse a partner, so until nothing new is refused
        $excluded = [];
        do {
            $plan = $this->plan($message, $context, $entries, $excluded, $actor, $now);
            $refused = $this->tableNumberClashes($plan, $excluded)
                + self::puzzlesMismatches($message, $plan, $excluded, $context['format'])
                + self::groupRefusals($message, $plan, $excluded);
            $excluded += $refused;
        } while ($refused !== []);

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
                RoundResultField::Puzzles => $entry->recordPuzzles(self::puzzles($change->to)),
                RoundResultField::MissingPieces => $entry->recordMissingPieces(self::missingPieces($change->to)),
                // A proposal change writes no entry field - TimeProposalResolver decides the proposal below
                RoundResultField::Proposal, null => throw new \LogicException('An applied change has a field of the entry.'),
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

        $outcomes = $plan['outcomes'];
        $changedProposalIds = [];

        if ($context['proposals'] !== null) {
            $resolved = $this->timeProposalResolver->resolve($message->changes, $outcomes, $context['proposals'], $receipts, $actor, $now);
            $outcomes = $resolved['outcomes'];
            $changedProposalIds = $resolved['changedProposalIds'];
        } else {
            // Proposals off: a confirmation names a proposal nobody can see - the result stands, the answer says so
            foreach ($message->changes as $index => $change) {
                if ($change->proposalId !== null && $change->field === RoundResultField::Result) {
                    $outcomes[$index] = $outcomes[$index]->withProposal($change->proposalId, 'unknown');
                }
            }
        }

        return new RecordedRoundResults(array_values($outcomes), array_keys($changed), false, $changedProposalIds);
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
                'puzzles' => $entry->officialPuzzles(),
                'missing_pieces' => $entry->resultMissingPieces,
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
                    'puzzles' => null,
                    'missing_pieces' => null,
                    'enteredAt' => null,
                    'enteredById' => null,
                    'enteredByName' => null,
                ];
                $pendingNew[$refString] = $change->newEntry;
            }

            if ($change->field === RoundResultField::Proposal) {
                // A judge's "Not right" (competition toolkit P1): applied = rejected now, unchanged = no longer pending.
                // Referees may send it (R-review-and-plan.md §1.2); it changes nothing of the entry
                $status = $excluded[$index] ?? ($context['proposals'] === null ? 'proposals_off' : TimeProposalResolver::rejectionStatus($change, $context['proposals']));
                $outcomes[] = is_string($status)
                    ? self::outcome($change, RoundResultChangeStatus::Rejected, $status, null)
                    : self::outcome($change, $status, null, null);

                continue;
            }

            $current = self::value($state[$refString], $change->field);
            $refusal = $excluded[$index] ?? (
                // The puzzle choice is part of the result - a referee records both (A-round-formats.md §2.4), and decides
                // a competitor's time proposal (competition toolkit P1)
                $message->resultsOnly && !in_array($change->field, [RoundResultField::Result, RoundResultField::Puzzles, RoundResultField::Proposal, RoundResultField::MissingPieces], true) ? 'results_only' : null
            ) ?? self::valueRefusal($change, $round, $context['format']) ?? (
                // Pieces missing of a finished puzzle - the entry must be finished at this point of the set (T6)
                $change->field === RoundResultField::MissingPieces && $change->to !== null && self::same($change->field, $current, $change->to) === false && $state[$refString]['result']->isFinished() === false
                    ? 'missing_pieces_not_finished'
                    : null
            );

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
     * The round's format is read (under its lock) only by a set that can need it: one recording puzzles, or one
     * recording results on a `pick` round. A plain set of a `total` round never reads it.
     */
    private static function readsFormat(RecordRoundResults $message, CompetitionRound $round): bool
    {
        foreach ($message->changes as $change) {
            if ($change->field === RoundResultField::Puzzles) {
                return true;
            }

            if ($change->field === RoundResultField::Result && $round->puzzleFormat === RoundPuzzleFormat::Pick) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rule 6 on a `pick` round: the record against the result, as the set leaves the entry. An entry the set changed
     * either of them on that ends up inconsistent gets every one of those changes refused.
     *
     * @param Plan $plan
     * @param array<int, string> $excluded
     * @param Format $format
     * @return array<int, string> change index => reason, only changes not excluded yet
     */
    private static function puzzlesMismatches(RecordRoundResults $message, array $plan, array $excluded, null|array $format): array
    {
        if ($format === null) {
            return [];
        }

        /** @var array<string, list<int>> $appliedByEntry */
        $appliedByEntry = [];
        foreach ($plan['applied'] as $index) {
            $change = $message->changes[$index];
            $ref = $change->entryRef()?->toString();

            if ($ref !== null && ($change->field === RoundResultField::Result || $change->field === RoundResultField::Puzzles)) {
                $appliedByEntry[$ref][] = $index;
            }
        }

        $refused = [];
        foreach ($appliedByEntry as $ref => $indexes) {
            $entryState = $plan['state'][$ref] ?? null;

            if ($entryState === null || RoundEntryPuzzles::consistencyRefusal($entryState['result'], $entryState['puzzles'], $format['toSolve'], $format['pieces']) === null) {
                continue;
            }

            foreach ($indexes as $index) {
                if (!isset($excluded[$index])) {
                    $refused[$index] = 'puzzles_mismatch';
                }
            }
        }

        return $refused;
    }

    /**
     * Rule 7: the `result`, `puzzles` and `missing_pieces` (T6) changes of one entry in one set apply together or not at
     * all - when one of them is refused or a conflict, the applied ones are refused as `group_refused`. An entry with
     * only one of these kinds in the set has no group (a set of `result` changes only behaves as it always did).
     *
     * @param Plan $plan
     * @param array<int, string> $excluded
     * @return array<int, string> change index => reason, only changes not excluded yet
     */
    private static function groupRefusals(RecordRoundResults $message, array $plan, array $excluded): array
    {
        /** @var array<string, array{kinds: array<string, true>, indexes: list<int>}> $groups */
        $groups = [];
        foreach ($message->changes as $index => $change) {
            $ref = $change->entryRef()?->toString();
            $grouped = [RoundResultField::Result, RoundResultField::Puzzles, RoundResultField::MissingPieces];

            if ($ref === null || $change->field === null || !in_array($change->field, $grouped, true)) {
                continue;
            }

            $groups[$ref] ??= ['kinds' => [], 'indexes' => []];
            $groups[$ref]['kinds'][$change->field->value] = true;
            $groups[$ref]['indexes'][] = $index;
        }

        $refused = [];
        foreach ($groups as $group) {
            if (count($group['kinds']) < 2) {
                continue;
            }

            $failed = false;
            foreach ($group['indexes'] as $index) {
                $status = $plan['outcomes'][$index]->status;
                $failed = $failed || $status === RoundResultChangeStatus::Rejected || $status === RoundResultChangeStatus::Conflict;
            }

            if ($failed === false) {
                continue;
            }

            foreach ($group['indexes'] as $index) {
                if ($plan['outcomes'][$index]->status === RoundResultChangeStatus::Applied && !isset($excluded[$index])) {
                    $refused[$index] = 'group_refused';
                }
            }
        }

        return $refused;
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

    /**
     * @param Format $format
     */
    private static function valueRefusal(RoundResultChange $change, CompetitionRound $round, null|array $format): null|string
    {
        if ($change->field === RoundResultField::Puzzles) {
            // Rules 1-5 (RoundEntryPuzzles) - only a `pick` round takes a record
            if ($format === null) {
                return 'puzzles_not_used';
            }

            return self::puzzles($change->to)?->itemsRefusal($format['toSolve'], $format['pieces']);
        }

        if ($change->field === RoundResultField::MissingPieces) {
            $missingPieces = self::missingPieces($change->to);
            // Below the puzzle's pieces when the round has one puzzle - a "finished" puzzle has most of its pieces
            $piecesCount = $missingPieces !== null && $format === null ? $round->singlePuzzlePiecesCount() : null;
            $maximum = $piecesCount !== null ? min($piecesCount - 1, self::MAX_MISSING_PIECES) : self::MAX_MISSING_PIECES;

            return $missingPieces !== null && ($missingPieces < 1 || $missingPieces > $maximum) ? 'invalid_missing_pieces' : null;
        }

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
            // A `pick` round: K × its biggest puzzle − 1 - a record pins the exact sum (rule 6, puzzlesMismatches())
            $piecesCount = $format === null ? $round->singlePuzzlePiecesCount() : null;
            $maximum = match (true) {
                $format !== null => RoundEntryPuzzles::unfinishedMaximum($format['toSolve'], $format['pieces']),
                $piecesCount !== null => $piecesCount - 1,
                default => self::MAX_PIECES_PLACED,
            };

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
    private static function value(array $entryState, RoundResultField $field): null|RoundEntryResult|RoundEntryPuzzles|int|bool
    {
        return match ($field) {
            RoundResultField::Result => $entryState['result'],
            RoundResultField::TableNumber => $entryState['table_number'],
            RoundResultField::Qualified => $entryState['qualified'],
            RoundResultField::Puzzles => $entryState['puzzles'],
            RoundResultField::MissingPieces => $entryState['missing_pieces'],
            RoundResultField::Proposal => null,
        };
    }

    /**
     * @param EntryState $entryState
     * @return EntryState
     */
    private static function withValue(array $entryState, RoundResultField $field, null|RoundEntryResult|RoundEntryPuzzles|int|bool $value, Player $actor, DateTimeImmutable $now): array
    {
        if ($field === RoundResultField::Result) {
            $entryState['result'] = self::result($value);
            // HasOfficialResult::recordResult()'s rule: pieces missing stay only next to a finished time
            $entryState['missing_pieces'] = $entryState['result']->isFinished() ? $entryState['missing_pieces'] : null;
            $entryState['enteredAt'] = $now;
            $entryState['enteredById'] = $actor->id->toString();
            $entryState['enteredByName'] = $actor->name ?? '#' . strtoupper($actor->code);
        } elseif ($field === RoundResultField::TableNumber) {
            $entryState['table_number'] = self::tableNumber($value);
        } elseif ($field === RoundResultField::Puzzles) {
            $entryState['puzzles'] = self::puzzles($value);
        } elseif ($field === RoundResultField::MissingPieces) {
            $entryState['missing_pieces'] = self::missingPieces($value);
        } else {
            $entryState['qualified'] = $value === true;
        }

        return $entryState;
    }

    private static function same(RoundResultField $field, null|RoundEntryResult|RoundEntryPuzzles|int|bool $a, null|RoundEntryResult|RoundEntryPuzzles|int|bool $b): bool
    {
        return match ($field) {
            RoundResultField::Result => self::result($a)->equals(self::result($b)),
            RoundResultField::TableNumber, RoundResultField::MissingPieces => self::tableNumber($a) === self::tableNumber($b),
            RoundResultField::Qualified => ($a === true) === ($b === true),
            RoundResultField::Puzzles => RoundEntryPuzzles::same(self::puzzles($a), self::puzzles($b)),
            RoundResultField::Proposal => false,
        };
    }

    private static function result(null|RoundEntryResult|RoundEntryPuzzles|int|bool $value): RoundEntryResult
    {
        return $value instanceof RoundEntryResult ? $value : RoundEntryResult::none();
    }

    private static function tableNumber(null|RoundEntryResult|RoundEntryPuzzles|int|bool $value): null|int
    {
        return is_int($value) ? $value : null;
    }

    private static function missingPieces(null|RoundEntryResult|RoundEntryPuzzles|int|bool $value): null|int
    {
        return is_int($value) ? $value : null;
    }

    private static function puzzles(null|RoundEntryResult|RoundEntryPuzzles|int|bool $value): null|RoundEntryPuzzles
    {
        return $value instanceof RoundEntryPuzzles ? $value : null;
    }

    /**
     * @param null|EntryState $entryState
     */
    private static function outcome(
        RoundResultChange $change,
        RoundResultChangeStatus $status,
        null|string $reason,
        null|RoundEntryResult|RoundEntryPuzzles|int|bool $current,
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
