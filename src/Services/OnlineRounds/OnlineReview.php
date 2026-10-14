<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Query\GetOnlineFinishPhotoChecks;
use SpeedPuzzling\Web\Query\GetOnlineMomentVerdicts;
use SpeedPuzzling\Web\Query\GetOnlineReviewData;
use SpeedPuzzling\Web\Results\OnlineFinishPhotoCheck;
use SpeedPuzzling\Web\Results\OnlineReviewCandidate;
use SpeedPuzzling\Web\Results\OnlineReviewEntry;
use SpeedPuzzling\Web\Results\OnlineReviewTap;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineReviewAssessment;
use SpeedPuzzling\Web\Value\OnlineReviewStatus;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * The review of an online round (docs/features/online-competitions/README.md §5.9) - what its page and JSON show:
 * - list(): one row per participant entered in the round (OnlineReviewEntry - status, suggested and official result,
 *   flags), the round's clock, the subscription to the round's private results topic (another reviewer's or the results
 *   desk's saves) - the entries, their events and the round's gap marks (a raise marked as a gap is never suggested -
 *   stream RG), three statements whatever the number of participants (plus the round's puzzle once);
 * - detail(): one participant's row plus everything behind it - the taps, the finish candidates (Phase 2: raised hands
 *   the phone's detector held), the events in order and the evidence with short-lived URLs (MEDIA_URL_SECONDS: the
 *   reviewer looks at a participant for a few minutes; the page asks again when it comes back) - a photo or a burst the
 *   phone has not uploaded yet is listed as pending, never hidden (README §5.11), one that never arrived and never will
 *   (OnlineEntryMedia::$abandonedAt) as abandoned; and the moments a reviewer marked as showing a gap (`gaps` - the G
 *   key stored, stream RG; one statement more);
 * - assessed(): the rows with their OnlineReviewAssessment - the accuracy report (with the round's gap marks).
 * Phase 3 - the completeness check (README §5.8, advisory), only while it is on (OnlineCvSettings; off = no key of it in
 * either answer, the page as before): `round.completenessCheck`, each row's `check` = the check of the photo the review
 * opens on (the suggested candidate's or tap's - the page orders the list by it: gaps, unsure, not checked, complete),
 * each finish photo's own `check` in the detail (GetOnlineFinishPhotoChecks - one statement more each). Nothing else
 * reads it: not the suggestion, not a confirmation, not the official time.
 * The round's detection mode decides the suggestion (OnlineReviewAssessment: `on` = the first candidate, unless a tap
 * came first) - `round.detection`. In a `shadow` round the reviewer never sees the detection (stream P2F, README §5.7:
 * the button is the official moment there - the cleanest delta data): the row, the detail, its events and its evidence
 * carry no candidate, nothing of the phone's detector (forReviewer()) - the reviewer steps through the taps only, as in
 * `off`; the accuracy report (assessed()) has them all.
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
        private OnlineCvSettings $onlineCvSettings,
        private GetOnlineFinishPhotoChecks $getOnlineFinishPhotoChecks,
        private GetOnlineMomentVerdicts $getOnlineMomentVerdicts,
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
            static fn (array $assessed): OnlineReviewEntry => self::forReviewer($assessed['entry'], $onlineRound->detectionMode),
            $this->assessed($round, $onlineRound->detectionMode, $clock, $asOfMs, $piecesTotal, $this->getOnlineMomentVerdicts->gapsOfRound($round->id->toString())),
        );
        $roundData = self::round($round, $onlineRound, $clock, $piecesTotal);

        if ($this->onlineCvSettings->isOn()) {
            $photos = $this->getOnlineFinishPhotoChecks->forRound($round->id->toString());
            $entries = array_map(
                static fn (OnlineReviewEntry $entry): OnlineReviewEntry => $entry->withCheck(self::suggestedPhotoCheck($entry, $photos[$entry->onlineEntryId ?? ''] ?? [])),
                $entries,
            );
            $roundData['completenessCheck'] = true;
        }

        $mercure = $this->officialResultsSubscription->forRound($round->id->toString());

        return [
            // Taken once everything is read - every answer is also a clock sample for the page
            'serverNowMs' => RoundClock::milliseconds($this->clock->now()),
            'asOfMs' => $asOfMs,
            'round' => $roundData,
            'entries' => $entries,
            'mercure' => $mercure,
        ];
    }

    /**
     * @return null|array{serverNowMs: int, asOfMs: int, round: array<string, mixed>, entry: OnlineReviewEntry, taps: list<OnlineReviewTap>, candidates: list<OnlineReviewCandidate>, gaps: list<string>, events: list<array<string, mixed>>, media: list<array<string, mixed>>}
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
        $detection = $onlineRound->detectionMode;
        // Every mark of the entry - the assessment never suggests a marked raise; the page gets those it shows
        $gaps = $onlineEntryId === null ? [] : $this->getOnlineMomentVerdicts->gapsOfEntry($onlineEntryId);
        [$entry, $assessment] = self::entry($row, $events, $clock, $asOfMs, $piecesTotal, $detection, $gaps);
        $hiddenCandidates = $detection === OnlineDetectionMode::Shadow
            ? array_fill_keys(array_map(static fn (OnlineReviewCandidate $candidate): string => strtolower($candidate->eventId), $assessment->candidates), true)
            : [];
        $media = $onlineEntryId === null ? [] : $this->getOnlineReviewData->media($onlineEntryId);
        $hidden = self::hiddenFromReviewer($detection, $events);
        $roundData = self::round($round, $onlineRound, $clock, $piecesTotal);
        // The completeness check of each finish photo - false: the check is off, no key of it anywhere
        $checks = false;

        if ($this->onlineCvSettings->isOn()) {
            $checks = $onlineEntryId === null ? [] : $this->getOnlineFinishPhotoChecks->forEntry($onlineEntryId);
            $roundData['completenessCheck'] = true;
        }

        return [
            'serverNowMs' => RoundClock::milliseconds($this->clock->now()),
            'asOfMs' => $asOfMs,
            'round' => $roundData,
            'entry' => self::forReviewer($entry, $detection),
            'taps' => $assessment->taps,
            'candidates' => $detection === OnlineDetectionMode::Shadow ? [] : $assessment->candidates,
            // The moments the reviewers marked as showing a gap (the G key) - of those shown here only
            'gaps' => array_values(array_filter($gaps, static fn (string $eventId): bool => !isset($hiddenCandidates[strtolower($eventId)]))),
            'events' => array_map(static fn (array $event): array => [
                'id' => $event['id'],
                'type' => $event['type'],
                'atMs' => $event['client_ms'],
                'receivedAtMs' => $event['received_at_ms'],
                'deviceId' => $event['device_id'],
                // Always a JSON object, an empty one too
                'data' => $event['payload'] === [] ? new \stdClass() : $event['payload'],
            ], array_values(array_filter($events, static fn (array $event): bool => !isset($hidden['events'][strtolower($event['id'])])))),
            'media' => array_map(
                fn (OnlineEntryMedia $item): array => $this->media($item, $checks),
                array_values(array_filter(
                    $media,
                    static fn (OnlineEntryMedia $item): bool => !($detection === OnlineDetectionMode::Shadow && $item->kind === OnlineMediaKind::Candidate)
                        && !isset($hidden['events'][strtolower($item->relatedEventId?->toString() ?? '')]),
                )),
            ),
        ];
    }

    /**
     * The event ids of the entry's finish moments marked as showing a gap (the reviewer's G, stream RG) - of the
     * moments the review shows: in a `shadow` round the taps' only
     *
     * @return list<string>
     */
    public function gaps(string $onlineEntryId, OnlineDetectionMode $detection): array
    {
        return $this->getOnlineMomentVerdicts->gapsOfEntry($onlineEntryId, candidates: $detection !== OnlineDetectionMode::Shadow);
    }

    /**
     * What a `shadow` round hides from the reviewer: the candidates' events (and with them their photos and bursts) and
     * the detector's own reports (`detector.changed`)
     *
     * @param list<OnlineReviewEventRow> $events
     * @return array{events: array<string, true>}
     */
    private static function hiddenFromReviewer(OnlineDetectionMode $detection, array $events): array
    {
        $hidden = [];

        if ($detection === OnlineDetectionMode::Shadow) {
            foreach ($events as $event) {
                if ($event['type'] === OnlineEventType::FinishCandidate->value || $event['type'] === OnlineEventType::DetectorChanged->value) {
                    $hidden[strtolower($event['id'])] = true;
                }
            }
        }

        return ['events' => $hidden];
    }

    /**
     * The row as the reviewer sees it - in a `shadow` round without the detection (no candidates, no detection error)
     */
    private static function forReviewer(OnlineReviewEntry $entry, OnlineDetectionMode $detection): OnlineReviewEntry
    {
        if ($detection !== OnlineDetectionMode::Shadow) {
            return $entry;
        }

        return new OnlineReviewEntry(
            participantId: $entry->participantId,
            entryId: $entry->entryId,
            onlineEntryId: $entry->onlineEntryId,
            name: $entry->name,
            country: $entry->country,
            status: $entry->status,
            suggestion: $entry->suggestion,
            official: $entry->official,
            confirmedMomentMs: $entry->confirmedMomentMs,
            confirmedSource: $entry->confirmedSource,
            reviewedAtMs: $entry->reviewedAtMs,
            adjustmentMs: $entry->adjustmentMs,
            flags: $entry->flags,
            taps: $entry->taps,
            readyAtMs: $entry->readyAtMs,
            candidates: 0,
            detectionErrorMs: null,
        );
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
        $gaps = $row['online_entry_id'] === null ? [] : $this->getOnlineMomentVerdicts->gapsOfEntry($row['online_entry_id']);
        [$entry] = self::entry($row, $events, RoundClock::fromRound($round, $now), RoundClock::milliseconds($now), $round->singlePuzzlePiecesCount(), $detection, $gaps);

        return self::forReviewer($entry, $detection);
    }

    /**
     * Every participant's row with its assessment and its events (by moment), by name - with the reviewers' gap marks
     * when given (the accuracy report: GetOnlineMomentVerdicts::gapsOfRound(), online entry id => event ids)
     *
     * @param array<string, list<string>> $gaps
     * @return list<array{entry: OnlineReviewEntry, assessment: OnlineReviewAssessment, events: list<OnlineReviewEventRow>, activeDeviceId: null|string}>
     */
    public function assessed(
        CompetitionRound $round,
        OnlineDetectionMode $detection = OnlineDetectionMode::Off,
        null|RoundClock $clock = null,
        null|int $nowMs = null,
        null|int $piecesTotal = null,
        array $gaps = [],
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
            $entryGaps = $row['online_entry_id'] === null ? [] : ($gaps[$row['online_entry_id']] ?? []);
            [$entry, $assessment] = self::entry($row, $events, $clock, $nowMs, $piecesTotal, $detection, $entryGaps);
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
     * @param list<string> $gaps the event ids of the entry's moments marked as a gap
     * @return array{OnlineReviewEntry, OnlineReviewAssessment}
     */
    private static function entry(array $row, array $events, RoundClock $clock, int $nowMs, null|int $piecesTotal, OnlineDetectionMode $detection, array $gaps = []): array
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
            gaps: $gaps,
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
            // Where the confirmed moment came from - an unfinished confirmation has no moment, so no source to show (it is
            // stored as `reviewer`, the one who decided - shown as "a frame picked in the review" it read wrong)
            confirmedSource: $row['confirmed_moment_ms'] === null ? null : $row['confirmed_source'],
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
     * The completeness check of the photo the review opens on: the suggested candidate's photo, else the suggested
     * tap's - null without such a photo (a participant without a finish, unfinished at time-up)
     *
     * @param list<array{media_id: string, online_entry_id: string, kind: string, related_event_id: null|string, taken_ms: int, uploaded: bool, check: null|OnlineFinishPhotoCheck}> $photos
     */
    private static function suggestedPhotoCheck(OnlineReviewEntry $entry, array $photos): null|OnlineFinishPhotoCheck
    {
        $suggestion = $entry->suggestion;
        $photo = null;

        if ($suggestion->candidateEventId !== null) {
            $photo = GetOnlineFinishPhotoChecks::photoOf($photos, OnlineMediaKind::Candidate, $suggestion->candidateEventId);
        } elseif ($suggestion->tapEventId !== null) {
            $photo = GetOnlineFinishPhotoChecks::photoOf($photos, OnlineMediaKind::Button, $suggestion->tapEventId);
        }

        return $photo === null ? null : $photo['check'];
    }

    /**
     * @param false|array<string, OnlineFinishPhotoCheck> $checks the completeness checks by media id - false: the check is off
     * @return array<string, mixed>
     */
    private function media(OnlineEntryMedia $media, false|array $checks = false): array
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
            // Phase 3: a finish photo's completeness check, only while the check is on (pending until it comes)
            ...($checks !== false && $media->kind->isFinishPhoto()
                ? ['check' => $checks[$media->id->toString()] ?? ($media->abandonedAt === null ? OnlineFinishPhotoCheck::pending() : null)]
                : []),
        ];
    }
}
