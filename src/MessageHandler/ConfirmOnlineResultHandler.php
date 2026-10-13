<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Exceptions\OnlineResultRefused;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\ConfirmOnlineResult;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Query\GetOnlineEntryRecordedMoments;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\ConfirmedOnlineResult;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\OnlineReviewStatus;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundResultChange;
use SpeedPuzzling\Web\Value\RoundResultChangeStatus;
use SpeedPuzzling\Web\Value\RoundResultField;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The reviewer confirms a participant's online result (docs/features/online-competitions/README.md §5.9) - the
 * decision on the online entry and the official result, in ONE transaction under the event's participants lock (the
 * message is SerializedByLock with CompetitionParticipantsLock):
 *
 * 1. Everything is checked first, nothing is written yet: the round uses online timing and is the authorised event's,
 *    the participant is entered in it and their phone made contact (an entry without an online entry has no evidence -
 *    its result goes through the results desk), exactly one of a moment and pieces, the round has started. A finish
 *    moment must lie between the round's start and its end and be a moment the phone recorded
 *    (GetOnlineEntryRecordedMoments - "the review only chooses between recorded moments", README §1 P2); its official
 *    time is floor((moment − start) / 1000) seconds. Unfinished: the pieces placed, the reviewer's own count (from the
 *    participant's report and the end photo).
 * 2. The official result goes through the one write path - RecordRoundResultsHandler, called directly (same
 *    transaction, same lock, no second dispatch) with one change: a new UUIDv7, `participant_round:<id>`, `result`,
 *    `from` = the result the reviewer saw (`seenResult` - what the review's detail showed when they decided), `to` =
 *    {"seconds": n} or {"piecesPlaced": n}, acting player = the reviewer. Its own three-way check decides: the entry
 *    holds `to` already - unchanged; it holds what the reviewer saw - applied; it holds anything else (the results desk
 *    or another reviewer entered a result meanwhile) - `conflict`, never overwritten: OnlineResultRefused 409 with the
 *    current result, the reviewer decides again. Its planning writes nothing when it refuses, so a refusal (`conflict`
 *    409, `rejected` 422) leaves no change behind in the entity manager either.
 * 3. Only then the online entry takes the decision (OnlineEntry::confirm()): the moment, its source, the reviewer, the
 *    time. Confirming the very same decision again (an unchanged result, the same moment and source) writes nothing.
 *
 * The controller tells the other open pages after the dispatch returned (OfficialResultsLiveUpdates::entriesChanged()).
 */
#[AsMessageHandler]
readonly final class ConfirmOnlineResultHandler
{
    public function __construct(
        private OnlineRoundRepository $onlineRoundRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private OnlineEntryRepository $onlineEntryRepository,
        private PlayerRepository $playerRepository,
        private GetOnlineEntryRecordedMoments $getOnlineEntryRecordedMoments,
        private RecordRoundResultsHandler $recordRoundResultsHandler,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OnlineRoundNotFound the round does not use online timing, or it is another event's
     * @throws OnlineResultRefused nothing was written
     */
    public function __invoke(ConfirmOnlineResult $message): ConfirmedOnlineResult
    {
        $onlineRound = $this->onlineRoundRepository->ofRound($message->roundId);

        if ($onlineRound === null || $onlineRound->round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new OnlineRoundNotFound();
        }

        $round = $onlineRound->round;
        $entry = $this->participantRoundRepository->findOfParticipantInRound($message->participantId, $round->id->toString())
            ?? throw OnlineResultRefused::rejectedByOfficialResults('entry_not_found');
        $onlineEntry = $this->onlineEntryRepository->ofEntry($entry->id->toString())
            ?? throw OnlineResultRefused::because(OnlineResultRefused::NOT_CONNECTED);
        $reviewer = $this->playerRepository->get($message->actingPlayerId);
        $now = $this->clock->now();

        [$result, $momentMs, $source] = $this->decision($message, $round, $onlineEntry, $now);

        $recorded = ($this->recordRoundResultsHandler)(new RecordRoundResults(
            competitionId: $message->competitionId,
            roundId: $round->id->toString(),
            actingPlayerId: $reviewer->id->toString(),
            changes: [
                RoundResultChange::forEntry(
                    clientChangeId: Uuid::uuid7()->toString(),
                    entry: $entry->entryRef(),
                    field: RoundResultField::Result,
                    // What the reviewer saw - never the value read here, which would make a result entered meanwhile
                    // look like the one they decided on
                    from: $message->seenResult,
                    to: $result,
                ),
            ],
            // The review writes results only
            resultsOnly: true,
        ));

        $outcome = $recorded->outcomes[0] ?? throw new \LogicException('The official results write path answers every change.');

        if ($outcome->status === RoundResultChangeStatus::Conflict) {
            throw OnlineResultRefused::conflict(
                $outcome->reason ?? 'changed_meanwhile',
                $outcome->current instanceof RoundEntryResult ? $outcome->current : $entry->officialResult(),
            );
        }

        if ($outcome->status === RoundResultChangeStatus::Rejected) {
            throw OnlineResultRefused::rejectedByOfficialResults($outcome->reason ?? 'invalid_change');
        }

        $sameDecision = $outcome->status === RoundResultChangeStatus::Unchanged
            && $onlineEntry->reviewStatus === OnlineReviewStatus::Confirmed
            && $onlineEntry->confirmedMomentMs === $momentMs
            && $onlineEntry->confirmedSource === $source;

        if ($sameDecision === false) {
            $onlineEntry->confirm($momentMs, $source, $reviewer, $now);
        }

        return new ConfirmedOnlineResult(
            status: $outcome->status,
            entryRef: $entry->entryRef()->toString(),
            result: $result,
            momentMs: $momentMs,
            source: $source,
            changedEntryRefs: $recorded->changedEntryRefs,
            reviewRecorded: $sameDecision === false,
        );
    }

    /**
     * What the confirmation asks for - checked before anything is written
     *
     * @return array{RoundEntryResult, null|int, OnlineConfirmedSource} the official result, the moment, its source
     * @throws OnlineResultRefused
     */
    private function decision(ConfirmOnlineResult $message, CompetitionRound $round, OnlineEntry $onlineEntry, DateTimeImmutable $now): array
    {
        $momentMs = $message->momentMs;

        // Exactly one of them: a finish moment, or the pieces placed of an unfinished puzzle
        if (($momentMs === null) === ($message->pieces === null)) {
            throw OnlineResultRefused::because(OnlineResultRefused::INVALID_CONFIRMATION);
        }

        // A count is the reviewer's own - no device moment behind it
        if ($momentMs === null && $message->source !== OnlineConfirmedSource::Reviewer) {
            throw OnlineResultRefused::because(OnlineResultRefused::INVALID_CONFIRMATION);
        }

        $clock = RoundClock::fromRound($round, $now);
        $startedAtMs = $clock->startedAtMs;

        // Nobody finished or ran out of time in a round that has not started
        if ($startedAtMs === null || $clock->status === RoundClock::WAITING || $clock->status === RoundClock::SCHEDULED) {
            throw OnlineResultRefused::because(OnlineResultRefused::ROUND_NOT_STARTED);
        }

        if ($momentMs === null) {
            assert($message->pieces !== null);

            return [RoundEntryResult::unfinished($message->pieces), null, OnlineConfirmedSource::Reviewer];
        }

        if ($momentMs < $startedAtMs) {
            throw OnlineResultRefused::because(OnlineResultRefused::MOMENT_BEFORE_START);
        }

        // A finish after the end does not count (README §5.10) - the end is the stop or the time limit, whichever came first
        if ($clock->endsAtMs !== null && $momentMs > $clock->endsAtMs) {
            throw OnlineResultRefused::because(OnlineResultRefused::MOMENT_AFTER_END);
        }

        if ($this->getOnlineEntryRecordedMoments->isRecorded($onlineEntry->id->toString(), $message->source, $momentMs) === false) {
            throw OnlineResultRefused::because(OnlineResultRefused::MOMENT_NOT_RECORDED);
        }

        return [RoundEntryResult::finished(intdiv($momentMs - $startedAtMs, 1000)), $momentMs, $message->source];
    }
}
