<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Rfc4122\FieldsInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Entity\CompetitionTimeProposal;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\ProposeRoundTime;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTimeProposalRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Services\CompetitionToolkit\TimeProposalOwnership;
use SpeedPuzzling\Web\Services\CompetitionToolkit\TimeProposalTiming;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundStopwatchStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stores a competitor's time proposal as `pending` - or refuses it and writes nothing (competition toolkit P1,
 * toolkit/B-qr-competitor-page.md §5.3 "Timing honesty" with R-review-and-plan.md §2.4). In this order:
 *
 * 1. `proposals_off` - the competition has time proposals off (or is online: online rounds have their own finish).
 * 2. `invalid_proposal_id` - the id is no UUIDv7 made within 24 h of the receipt; or it is known but of another entry
 *    or another sender (answering its state would leak it) - a known id of the same entry and sender is answered
 *    `known`, never stored twice.
 * 3. `entry_not_in_round`, then ownership (TimeProposalOwnership): `not_your_entry`, `linked_after_start`.
 * 4. Timing against the stopwatch as stored - the tap moment is the phone's synced clock (`tappedAtMs`):
 *    `clock_ahead` (more than 2 s after the receipt - impossible beyond a sane clock error), `not_running` (the stopwatch
 *    was not running at the tap: not started, a countdown, after the Stop - a tap before it counts), `times_up`
 *    (after the time limit - unfinished results are the judge's: pieces count), `too_late` (received more than 30 min
 *    after the round's end).
 * 5. `result_recorded` (the entry has an official result), `already_proposed` (one is pending - its id comes along).
 *
 * The seconds are counted from the stored start, both for the tap and for the receipt; more than 5 s between them is
 * `late` (lateByMs). Whether that lateness is a problem is the timing verdict (reliability hardening T2,
 * TimeProposalTiming::judge() over the message's evidence - the heartbeats of the competitor's page): on_time /
 * offline (the tap stands) / suspicious (the judge checks; the receipt is the default), plus `clockUncertain`.
 * A phone that timed against another stopwatch state (a pause/resume since) is flagged `clockChanged`. Nothing is
 * published here - the controller does after the commit.
 */
#[AsMessageHandler]
readonly final class ProposeRoundTimeHandler
{
    public const int CLOCK_AHEAD_TOLERANCE_MS = 2000;
    public const int ACCEPTED_AFTER_END_MS = 30 * 60_000;
    public const int PROPOSAL_ID_WINDOW_MS = 86_400_000;

    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private CompetitionTimeProposalRepository $proposalRepository,
        private PlayerRepository $playerRepository,
        private TimeProposalOwnership $ownership,
    ) {
    }

    public function __invoke(ProposeRoundTime $message): TimeProposalAnswer
    {
        $roundId = strtolower($message->roundId);

        try {
            $round = $this->roundRepository->getWithCompetition($roundId);
        } catch (CompetitionRoundNotFound) {
            return TimeProposalAnswer::refused('round_not_found', $roundId);
        }

        $competition = $round->competition;

        if ($competition->id->toString() !== strtolower($message->competitionId)) {
            return TimeProposalAnswer::refused('round_not_found', $roundId);
        }

        if ($competition->timeProposals === false || $competition->isOnline) {
            return TimeProposalAnswer::refused('proposals_off', $roundId);
        }

        if (self::isFreshUuidV7($message->proposalId, $message->receivedAtMs) === false) {
            return TimeProposalAnswer::refused('invalid_proposal_id', $roundId);
        }

        $ref = RoundEntryRef::tryFromString($message->entry);
        $known = $this->proposalRepository->find($message->proposalId);

        if ($known !== null) {
            $sameSender = $known->proposedByPlayer?->id->toString() === strtolower($message->actingPlayerId);

            // Only the same entry's same sender learns anything about a known id (R §2.4 item 2)
            return $ref !== null && $sameSender && $known->entryRef()->toString() === $ref->toString() && $known->round->id->equals($round->id)
                ? TimeProposalAnswer::known($known->id->toString(), $roundId)
                : TimeProposalAnswer::refused('invalid_proposal_id', $roundId);
        }

        $entry = $ref !== null ? $this->ownership->entryOf($round, $ref) : null;

        if ($ref === null || $entry === null) {
            return TimeProposalAnswer::refused(TimeProposalOwnership::ENTRY_NOT_IN_ROUND, $roundId);
        }

        $proposer = $this->ownership->proposerOf($round, $entry, $message->actingPlayerId);

        if (is_string($proposer)) {
            return TimeProposalAnswer::refused($proposer, $roundId);
        }

        $timingRefusal = self::timingRefusal($round->stopwatchStatus, $round->stopwatchStartedAt, $round->stopwatchStoppedAt, $round->minutesLimit, $message->tappedAtMs, $message->receivedAtMs);

        if ($timingRefusal !== null) {
            return TimeProposalAnswer::refused($timingRefusal, $roundId);
        }

        if ($entry->officialResult()->isNone() === false) {
            return TimeProposalAnswer::refused('result_recorded', $roundId);
        }

        $pending = $this->proposalRepository->findPendingOfEntry($ref);

        if ($pending !== null) {
            return TimeProposalAnswer::refused('already_proposed', $roundId, $pending->id->toString());
        }

        $startedAt = $round->stopwatchStartedAt;
        assert($startedAt !== null); // timingRefusal() refuses a round never started
        $startedAtMs = $startedAt->getTimestamp() * 1000;
        $receivedNow = new DateTimeImmutable('@' . intdiv($message->receivedAtMs, 1000));
        $storedHash = RoundClock::fromRound($round, $receivedNow)->stateHash();

        $timing = TimeProposalTiming::judge(
            tappedAtMs: $message->tappedAtMs,
            receivedAtMs: $message->receivedAtMs,
            heartbeat: $message->heartbeat,
            offsetMs: $message->offsetMs,
            rttMs: $message->rttMs,
            offsetSampleAgeMs: $message->offsetSampleAgeMs,
            sentAtMs: $message->sentAtMs,
        );

        $proposal = new CompetitionTimeProposal(
            id: Uuid::fromString(strtolower($message->proposalId)),
            round: $round,
            participantRound: $entry instanceof CompetitionParticipantRound ? $entry : null,
            team: $entry instanceof CompetitionTeam ? $entry : null,
            proposedByParticipant: $proposer,
            proposedByPlayer: $this->playerRepository->get($message->actingPlayerId),
            proposerLinkedAt: $proposer->connectedAt,
            tappedAtMs: $message->tappedAtMs,
            receivedAtMs: $message->receivedAtMs,
            clockOffsetMs: $message->offsetMs,
            clockRttMs: $message->rttMs,
            stopwatchStateHash: $message->stateHash !== null ? substr($message->stateHash, 0, 32) : null,
            startedAtMs: $startedAtMs,
            proposedSeconds: max(0, intdiv($message->tappedAtMs - $startedAtMs, 1000)),
            receiptSeconds: max(0, intdiv($message->receivedAtMs - $startedAtMs, 1000)),
            lateByMs: max(0, $message->receivedAtMs - $message->tappedAtMs),
            clockChanged: $message->stateHash !== null && $message->stateHash !== $storedHash,
            timingVerdict: $timing->verdict,
            lastHeardAtMs: $timing->lastHeardAtMs,
            clockUncertain: $timing->clockUncertain,
            offsetSampleAgeMs: $message->offsetSampleAgeMs !== null ? max(0, min($message->offsetSampleAgeMs, 2_000_000_000)) : null,
            queuedOffline: $message->queuedOffline,
            sendAttempts: $message->attempts !== null ? max(0, min($message->attempts, 1_000_000)) : null,
        );
        $this->proposalRepository->save($proposal);

        return TimeProposalAnswer::proposed($proposal->id->toString(), $roundId);
    }

    /**
     * The stopwatch as stored, at the tap - null when the tap is a moment the round was running. A round stopped since
     * (the judge's Stop - in person a pause) was running at every tap from its start up to the stop: an honest tap made
     * before the stop and received after it is accepted (flagged late by the receipt, and the 30-min `too_late` grace
     * applies); only a tap after the stop is `not_running` (P1F). Whole seconds, like the stored stop.
     */
    public static function timingRefusal(null|string $status, null|DateTimeImmutable $startedAt, null|DateTimeImmutable $stoppedAt, null|int $minutesLimit, int $tappedAtMs, int $receivedAtMs): null|string
    {
        if ($tappedAtMs > $receivedAtMs + self::CLOCK_AHEAD_TOLERANCE_MS) {
            return 'clock_ahead';
        }

        $tappedAt = new DateTimeImmutable('@' . intdiv($tappedAtMs, 1000));

        if ($startedAt === null) {
            return 'not_running';
        }

        if ($status === RoundStopwatchStatus::STOPPED) {
            if ($stoppedAt === null || $tappedAt->getTimestamp() > $stoppedAt->getTimestamp()) {
                return 'not_running';
            }
        } elseif (RoundStopwatchStatus::at($status, $startedAt, $tappedAt) !== RoundStopwatchStatus::RUNNING) {
            return 'not_running';
        }

        $startedAtMs = $startedAt->getTimestamp() * 1000;

        if ($tappedAtMs < $startedAtMs) {
            return 'not_running';
        }

        $limitEndsAtMs = $minutesLimit !== null ? $startedAtMs + $minutesLimit * 60_000 : null;

        if ($limitEndsAtMs !== null && $tappedAtMs > $limitEndsAtMs) {
            return 'times_up';
        }

        // The round's end: the time limit, or the Stop when it came first
        $stoppedAtMs = $status === RoundStopwatchStatus::STOPPED && $stoppedAt !== null ? $stoppedAt->getTimestamp() * 1000 : null;
        $endsAtMs = $stoppedAtMs !== null && ($limitEndsAtMs === null || $stoppedAtMs < $limitEndsAtMs) ? $stoppedAtMs : $limitEndsAtMs;

        if ($endsAtMs !== null && $receivedAtMs > $endsAtMs + self::ACCEPTED_AFTER_END_MS) {
            return 'too_late';
        }

        return null;
    }

    private static function isFreshUuidV7(string $proposalId, int $receivedAtMs): bool
    {
        if (!Uuid::isValid($proposalId)) {
            return false;
        }

        $uuid = Uuid::fromString(strtolower($proposalId));
        $fields = $uuid->getFields();

        if (!$fields instanceof FieldsInterface || $fields->getVersion() !== 7) {
            return false;
        }

        $madeAtMs = (int) $uuid->getDateTime()->format('Uv');

        return abs($receivedAtMs - $madeAtMs) <= self::PROPOSAL_ID_WINDOW_MS;
    }
}
