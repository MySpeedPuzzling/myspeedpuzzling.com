<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Query\GetOnlineReviewData;
use SpeedPuzzling\Web\Results\OnlineReviewCandidate;
use SpeedPuzzling\Web\Results\OnlineReviewEntry;
use SpeedPuzzling\Web\Results\OnlineReviewTap;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineReviewAssessment;
use SpeedPuzzling\Web\Value\OnlineReviewStatus;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * The review of an online round (docs/features/online-competitions/README.md §5.9) - what its page and JSON show:
 * - list(): one row per participant entered in the round (OnlineReviewEntry - status, suggested and official result,
 *   flags), the round's clock, the subscription to the round's private results topic (another reviewer's or the results
 *   desk's saves) - the entries and their events, two statements whatever the number of participants (plus the round's
 *   puzzle once);
 * - detail(): one participant's row plus everything behind it - the taps, the finish candidates (Phase 2: raised hands
 *   the phone's detector held), the events in order and the evidence with short-lived URLs (MEDIA_URL_SECONDS: the
 *   reviewer looks at a participant for a few minutes; the page asks again when it comes back) - a photo or a burst the
 *   phone has not uploaded yet is listed as pending, never hidden (README §5.11), one that never arrived and never will
 *   (OnlineEntryMedia::$abandonedAt) as abandoned;
 * - assessed(): the rows with their OnlineReviewAssessment - the accuracy report.
 * The round's detection mode decides the suggestion (OnlineReviewAssessment: `on` = the first candidate) - `round.detection`.
 *
 * @phpstan-import-type OnlineReviewEntryRow from GetOnlineReviewData
 * @phpstan-import-type OnlineReviewEventRow from GetOnlineReviewData
 */
readonly final class OnlineReview
{
    public const int MEDIA_URL_SECONDS = 900;

    public function __construct(
        private GetOnlineReviewData $getOnlineReviewData,
        private OnlineMediaStorage $onlineMediaStorage,
        private OfficialResultsSubscription $officialResultsSubscription,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{serverNowMs: int, asOfMs: int, round: array<string, mixed>, entries: list<OnlineReviewEntry>, mercure: null|array{url: string, topics: list<string>, token: string, expiresAt: string, expiresIn: int}}
     */
    public function list(CompetitionRound $round, OnlineRound $onlineRound): array
    {
        $asOf = $this->clock->now();
        $asOfMs = RoundClock::milliseconds($asOf);
        $clock = RoundClock::fromRound($round, $asOf);
        $piecesTotal = $round->singlePuzzlePiecesCount();
        $entries = array_map(
            static fn (array $assessed): OnlineReviewEntry => $assessed['entry'],
            $this->assessed($round, $onlineRound->detectionMode, $clock, $asOfMs, $piecesTotal),
        );
        $mercure = $this->officialResultsSubscription->forRound($round->id->toString());

        return [
            // Taken once everything is read - every answer is also a clock sample for the page
            'serverNowMs' => RoundClock::milliseconds($this->clock->now()),
            'asOfMs' => $asOfMs,
            'round' => self::round($round, $onlineRound, $clock, $piecesTotal),
            'entries' => $entries,
            'mercure' => $mercure,
        ];
    }

    /**
     * @return null|array{serverNowMs: int, asOfMs: int, round: array<string, mixed>, entry: OnlineReviewEntry, taps: list<OnlineReviewTap>, candidates: list<OnlineReviewCandidate>, events: list<array<string, mixed>>, media: list<array<string, mixed>>}
     *              null: the participant is not entered in the round (or not going)
     */
    public function detail(CompetitionRound $round, OnlineRound $onlineRound, string $participantId): null|array
    {
        $asOf = $this->clock->now();
        $asOfMs = RoundClock::milliseconds($asOf);
        $clock = RoundClock::fromRound($round, $asOf);
        $roundId = $round->id->toString();
        $row = $this->getOnlineReviewData->entries($roundId, $participantId)[0] ?? null;

        if ($row === null) {
            return null;
        }

        $onlineEntryId = $row['online_entry_id'];
        $events = $onlineEntryId === null ? [] : $this->getOnlineReviewData->events($roundId, $onlineEntryId);
        $piecesTotal = $round->singlePuzzlePiecesCount();
        [$entry, $assessment] = self::entry($row, $events, $clock, $asOfMs, $piecesTotal, $onlineRound->detectionMode);
        $media = $onlineEntryId === null ? [] : array_map($this->media(...), $this->getOnlineReviewData->media($onlineEntryId));

        return [
            'serverNowMs' => RoundClock::milliseconds($this->clock->now()),
            'asOfMs' => $asOfMs,
            'round' => self::round($round, $onlineRound, $clock, $piecesTotal),
            'entry' => $entry,
            'taps' => $assessment->taps,
            'candidates' => $assessment->candidates,
            'events' => array_map(static fn (array $event): array => [
                'id' => $event['id'],
                'type' => $event['type'],
                'atMs' => $event['client_ms'],
                'receivedAtMs' => $event['received_at_ms'],
                'deviceId' => $event['device_id'],
                // Always a JSON object, an empty one too
                'data' => $event['payload'] === [] ? new \stdClass() : $event['payload'],
            ], $events),
            'media' => $media,
        ];
    }

    /**
     * One participant's row as it is now - the answer to a confirmation; null when they are not entered in the round
     * (or not going)
     */
    public function row(CompetitionRound $round, string $participantId, OnlineDetectionMode $detection = OnlineDetectionMode::Off): null|OnlineReviewEntry
    {
        $now = $this->clock->now();
        $roundId = $round->id->toString();
        $row = $this->getOnlineReviewData->entries($roundId, $participantId)[0] ?? null;

        if ($row === null) {
            return null;
        }

        $events = $row['online_entry_id'] === null ? [] : $this->getOnlineReviewData->events($roundId, $row['online_entry_id']);
        [$entry] = self::entry($row, $events, RoundClock::fromRound($round, $now), RoundClock::milliseconds($now), $round->singlePuzzlePiecesCount(), $detection);

        return $entry;
    }

    /**
     * Every participant's row with its assessment and its events (by moment), by name
     *
     * @return list<array{entry: OnlineReviewEntry, assessment: OnlineReviewAssessment, events: list<OnlineReviewEventRow>, activeDeviceId: null|string}>
     */
    public function assessed(
        CompetitionRound $round,
        OnlineDetectionMode $detection = OnlineDetectionMode::Off,
        null|RoundClock $clock = null,
        null|int $nowMs = null,
        null|int $piecesTotal = null,
    ): array {
        $now = $this->clock->now();
        $nowMs ??= RoundClock::milliseconds($now);
        $clock ??= RoundClock::fromRound($round, $now);
        $piecesTotal ??= $round->singlePuzzlePiecesCount();
        $roundId = $round->id->toString();
        $rows = $this->getOnlineReviewData->entries($roundId);
        $eventsByEntry = [];

        foreach ($this->getOnlineReviewData->events($roundId) as $event) {
            $eventsByEntry[$event['online_entry_id']][] = $event;
        }

        $assessed = [];

        foreach ($rows as $row) {
            $events = $row['online_entry_id'] === null ? [] : ($eventsByEntry[$row['online_entry_id']] ?? []);
            [$entry, $assessment] = self::entry($row, $events, $clock, $nowMs, $piecesTotal, $detection);
            $assessed[] = ['entry' => $entry, 'assessment' => $assessment, 'events' => $events, 'activeDeviceId' => $row['active_device_id']];
        }

        return $assessed;
    }

    /**
     * The round as the review reads it: its clock (round_state.js), the puzzle's piece count (null with several
     * puzzles), how unfinished puzzles are reported, its zone, its finish detection (off / shadow / on)
     *
     * @return array<string, mixed>
     */
    public static function round(CompetitionRound $round, OnlineRound $onlineRound, RoundClock $clock, null|int $piecesTotal): array
    {
        return [
            ...OnlineLiveState::round($round, $clock),
            'piecesTotal' => $piecesTotal,
            'unfinishedMeasure' => $onlineRound->unfinishedMeasure->value,
            'timezone' => $round->displayTimezone(),
            'detection' => $onlineRound->detectionMode->value,
        ];
    }

    /**
     * @param OnlineReviewEntryRow $row
     * @param list<OnlineReviewEventRow> $events
     * @return array{OnlineReviewEntry, OnlineReviewAssessment}
     */
    private static function entry(array $row, array $events, RoundClock $clock, int $nowMs, null|int $piecesTotal, OnlineDetectionMode $detection): array
    {
        $assessment = OnlineReviewAssessment::of(
            clock: $clock,
            nowMs: $nowMs,
            confirmed: $row['review_status'] === OnlineReviewStatus::Confirmed->value,
            confirmedMomentMs: $row['confirmed_moment_ms'],
            reportedMeasure: $row['reported_measure'],
            reportedPieces: $row['reported_pieces'],
            piecesTotal: $piecesTotal,
            events: $events,
            storedFlags: $row['flags'],
            detection: $detection,
        );

        return [new OnlineReviewEntry(
            participantId: $row['participant_id'],
            entryId: $row['entry_id'],
            onlineEntryId: $row['online_entry_id'],
            name: $row['name'],
            country: $row['country'],
            status: $assessment->status,
            suggestion: $assessment->suggestion,
            official: RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], $row['result_did_not_start']),
            confirmedMomentMs: $row['confirmed_moment_ms'],
            confirmedSource: $row['confirmed_source'],
            reviewedAtMs: $row['reviewed_at_ms'],
            adjustmentMs: $assessment->adjustmentMs,
            flags: $assessment->flags,
            taps: count($assessment->taps),
            readyAtMs: $row['ready_at_ms'],
            candidates: count($assessment->candidates),
            detectionErrorMs: $assessment->detectionErrorMs,
        ), $assessment];
    }

    /**
     * @return array<string, mixed>
     */
    private function media(OnlineEntryMedia $media): array
    {
        $url = null;

        if ($media->uploadedAtMs !== null) {
            try {
                $url = $this->onlineMediaStorage->presignGet($media, self::MEDIA_URL_SECONDS);
            } catch (\Throwable $exception) {
                $this->logger->warning('Signing an online evidence object for the review failed', [
                    'media_id' => $media->id->toString(),
                    'exception' => $exception,
                ]);
            }
        }

        return [
            'id' => $media->id->toString(),
            'kind' => $media->kind->value,
            'takenMs' => $media->takenMs,
            'relatedEventId' => $media->relatedEventId?->toString(),
            // Registered, the phone has not reported the upload yet (offline, a store outage) - shown as pending
            'pending' => $media->uploadedAtMs === null && $media->abandonedAt === null,
            // Never arrived and never will (the phone gave the URL up, or nothing was there long after it ran out)
            'abandoned' => $media->uploadedAtMs === null && $media->abandonedAt !== null,
            'url' => $url,
            'bytes' => $media->verifiedBytes ?? $media->declaredBytes,
            'manifest' => $media->manifest,
        ];
    }
}
