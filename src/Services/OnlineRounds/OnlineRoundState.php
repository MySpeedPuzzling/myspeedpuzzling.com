<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Repository\OnlineEntryEventRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryRepository;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Value\OnlineAccessContext;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineReviewStatus;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * The state a participant's page lives on (`GET /api/compete/{token}/state`, docs/features/online-competitions/
 * implementation-plan.md §3.1): who they are, the round their page is about now with its clock and settings, their
 * entry, and the subscription to the round's stopwatch topic.
 *
 * - `asOfMs` is when the rows were read, `serverNowMs` when the answer was made - the page keeps whichever state is
 *   newest by `asOfMs`, so a slow answer never overwrites a newer push.
 * - `round` is null while the competition has no round with online timing; its clock fields are RoundClock's
 *   (`status`, `startedAtMs`, `stoppedAtMs`, `endsAtMs`, `minutesLimit`, `stateHash`).
 * - `piecesTotal` is the piece count of the round's one puzzle - null with several puzzles, or while it is kept secret
 *   entirely until the round starts.
 * - `entry` is null when the participant is not entered in the round; before their phone's first contact it holds the
 *   defaults. `taps` counts the Finished taps that were not undone, `candidates` the finish candidates - both of the
 *   round's current run only (a run is one start of the round clock: events from `startedAtMs` on; before the first
 *   start or after a Reset there is none). The reported pieces and the review are the current run's too (a Reset clears
 *   them - OnlineEntry::startFreshRun()).
 *
 * @phpstan-type OnlineStateRound array{
 *     id: string,
 *     name: string,
 *     minutesLimit: int,
 *     piecesTotal: null|int,
 *     status: RoundClock::WAITING|RoundClock::SCHEDULED|RoundClock::RUNNING|RoundClock::ENDED,
 *     startedAtMs: null|int,
 *     stoppedAtMs: null|int,
 *     endsAtMs: null|int,
 *     stateHash: string,
 *     settings: array{detection: string, video: bool, seeEachOther: bool, studio: bool, unfinishedMeasure: string},
 * }
 * @phpstan-type OnlineStateEntry array{
 *     confirmedMomentMs: null|int,
 *     candidates: int,
 *     taps: int,
 *     reportedPieces: null|int,
 *     review: string,
 * }
 * @phpstan-type OnlineState array{
 *     serverNowMs: int,
 *     asOfMs: int,
 *     participant: array{id: string, name: string, connectedPlayer: null|array{id: string, name: null|string, code: string}},
 *     competition: array{id: string, name: string},
 *     round: null|OnlineStateRound,
 *     entry: null|OnlineStateEntry,
 *     mercure: null|array{url: string, topics: list<string>, token: string, expiresAt: string, expiresIn: int},
 * }
 */
readonly final class OnlineRoundState
{
    public function __construct(
        private OnlineEntryRepository $onlineEntryRepository,
        private OnlineEntryEventRepository $onlineEntryEventRepository,
        private OfficialResultsSubscription $officialResultsSubscription,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return OnlineState
     */
    public function forParticipant(OnlineAccessContext $context): array
    {
        $participant = $context->participant;
        $player = $participant->player;
        $round = $this->round($context);
        $entry = $this->entry($context);
        $mercure = $context->round === null ? null : $this->officialResultsSubscription->forOnlineParticipant($context->round->id->toString());

        return [
            // Taken once everything is read - every answer is also a clock sample for the phone
            'serverNowMs' => RoundClock::milliseconds($this->clock->now()),
            'asOfMs' => $context->asOfMs,
            'participant' => [
                'id' => $participant->id->toString(),
                'name' => $participant->name,
                'connectedPlayer' => $player === null ? null : [
                    'id' => $player->id->toString(),
                    'name' => $player->name,
                    'code' => $player->code,
                ],
            ],
            'competition' => [
                'id' => $context->competition->id->toString(),
                'name' => $context->competition->name,
            ],
            'round' => $round,
            'entry' => $entry,
            'mercure' => $mercure,
        ];
    }

    /**
     * @return null|OnlineStateRound
     */
    private function round(OnlineAccessContext $context): null|array
    {
        $round = $context->round;
        $onlineRound = $context->onlineRound;
        $clock = $context->clock;

        if ($round === null || $onlineRound === null || $clock === null) {
            return null;
        }

        return [
            'id' => $round->id->toString(),
            'name' => $round->name,
            'minutesLimit' => $clock->minutesLimit,
            'piecesTotal' => self::piecesTotal($round, new DateTimeImmutable('@' . intdiv($context->asOfMs, 1000))),
            'status' => $clock->status,
            'startedAtMs' => $clock->startedAtMs,
            'stoppedAtMs' => $clock->stoppedAtMs,
            'endsAtMs' => $clock->endsAtMs,
            'stateHash' => $clock->stateHash(),
            'settings' => [
                'detection' => $onlineRound->detectionMode->value,
                'video' => $onlineRound->videoEnabled,
                'seeEachOther' => $onlineRound->participantsSeeEachOther,
                'studio' => $onlineRound->studioEnabled,
                'unfinishedMeasure' => $onlineRound->unfinishedMeasure->value,
            ],
        ];
    }

    /**
     * @return null|OnlineStateEntry
     */
    private function entry(OnlineAccessContext $context): null|array
    {
        if ($context->entry === null) {
            return null;
        }

        $onlineEntry = $this->onlineEntryRepository->ofEntry($context->entry->id->toString());
        $runStartedAtMs = $context->clock?->startedAtMs;
        $counts = $onlineEntry === null || $runStartedAtMs === null
            ? []
            : $this->onlineEntryEventRepository->typeCounts($onlineEntry->id->toString(), $runStartedAtMs);
        $taps = ($counts[OnlineEventType::FinishButton->value] ?? 0) - ($counts[OnlineEventType::FinishButtonUndone->value] ?? 0);

        return [
            'confirmedMomentMs' => $onlineEntry?->confirmedMomentMs,
            'candidates' => $counts[OnlineEventType::FinishCandidate->value] ?? 0,
            'taps' => max(0, $taps),
            'reportedPieces' => $onlineEntry?->reportedPieces,
            'review' => ($onlineEntry->reviewStatus ?? OnlineReviewStatus::Pending)->value,
        ];
    }

    private static function piecesTotal(CompetitionRound $round, DateTimeImmutable $now): null|int
    {
        if ($round->roundPuzzles->count() !== 1) {
            return null;
        }

        $roundPuzzle = $round->roundPuzzles->first();

        if ($roundPuzzle === false) {
            return null;
        }

        // A puzzle kept secret entirely until its reveal gives nothing away - its piece count neither
        if ($roundPuzzle->isHiddenAt($now) && ($roundPuzzle->hideMode ?? PuzzleHideMode::Entirely) === PuzzleHideMode::Entirely) {
            return null;
        }

        return $roundPuzzle->puzzle->piecesCount;
    }
}
