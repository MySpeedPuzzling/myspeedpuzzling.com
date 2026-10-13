<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Query\GetOnlineLiveParticipants;
use SpeedPuzzling\Web\Results\OnlineLiveParticipant;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Value\OnlineReviewStatus;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * What the control room of an online round shows (`online_live`, docs/features/online-competitions/implementation-plan.md
 * WP 1.7) - the page's first state and every poll after it (every 2-3 s while the page is shown):
 * - `round`: the round's clock as round_state.js reads it (RoundClock: status, the moments in epoch ms, the state hash,
 *   plus the stopwatch status as stored), `asOfMs` = when it was read - a pushed clock newer than that wins on the page;
 * - `participants`: OnlineLiveParticipant per participant entered in the round (GetOnlineLiveParticipants), with the
 *   last heartbeat of the device holding their entry from the presence (redis-state - never Postgres), another device
 *   open on the same link (`otherDevice`: its id and when it was seen), and the latest periodic photo's URL
 *   (OnlineMediaStorage::presignGet(), PHOTO_URL_SECONDS: long enough for the image to load, short enough that a URL
 *   leaking from the organiser's screen is soon worthless);
 * - `presenceAvailable`: false when the presence could not be read (logged) - the grid says so instead of showing
 *   everybody offline;
 * - `mercure`: the round's stopwatch topic (OfficialResultsSubscription::forOnlineParticipant() - public, start / cancel
 *   / end), its token renewed with every poll;
 * - `serverNowMs`: the server's time when the answer was made - a free clock sample.
 *
 * Statements: the participants and their photos - two, constant in the number of participants (the presence is one
 * Redis call).
 */
readonly final class OnlineLiveState
{
    public const int PHOTO_URL_SECONDS = 120;

    public function __construct(
        private GetOnlineLiveParticipants $getOnlineLiveParticipants,
        private OnlinePresence $onlinePresence,
        private OnlineMediaStorage $onlineMediaStorage,
        private OfficialResultsSubscription $officialResultsSubscription,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{serverNowMs: int, asOfMs: int, round: array<string, mixed>, presenceAvailable: bool, participants: list<OnlineLiveParticipant>, mercure: null|array{url: string, topics: list<string>, token: string, expiresAt: string, expiresIn: int}}
     */
    public function forRound(CompetitionRound $round): array
    {
        $roundId = $round->id->toString();
        $asOf = $this->clock->now();
        $clock = RoundClock::fromRound($round, $asOf);
        $rows = $this->getOnlineLiveParticipants->forRound($roundId);

        $presenceAvailable = true;

        try {
            $presence = $this->onlinePresence->forRound($roundId);
        } catch (\Throwable $exception) {
            $presenceAvailable = false;
            $presence = [];
            $this->logger->warning('Reading the online presence for the control room failed', [
                'round_id' => $roundId,
                'exception' => $exception,
            ]);
        }

        $photoUrls = $this->photoUrls(array_values(array_filter(array_column($rows, 'photo_id'), is_string(...))));
        $participants = [];

        foreach ($rows as $row) {
            $beat = $presence[strtolower($row['participant_id'])] ?? null;

            $participants[] = new OnlineLiveParticipant(
                participantId: $row['participant_id'],
                entryId: $row['entry_id'],
                name: $row['name'],
                country: $row['country'],
                seen: $row['online_entry_id'] !== null || $beat !== null,
                readyAtMs: $row['ready_at_ms'],
                activeDeviceId: $row['active_device_id'],
                readyClockRttMs: $row['clock_rtt_ms'],
                // Only another device beat - the holder's presence is not known
                presence: $beat === null || !array_key_exists('lastSeenMs', $beat) ? null : self::presence($beat),
                otherDevice: $beat === null ? null : self::otherDevice($beat),
                finishEventId: $row['tap_id'],
                finishMomentMs: $row['tap_ms'],
                finishReceivedAtMs: $row['tap_received_ms'],
                reportedMeasure: $row['reported_measure'],
                reportedPieces: $row['reported_pieces'],
                review: $row['review_status'] ?? OnlineReviewStatus::Pending->value,
                flags: $row['flags'],
                photoMediaId: $row['photo_id'],
                photoTakenMs: $row['photo_taken_ms'],
                photoUrl: $row['photo_id'] === null ? null : ($photoUrls[$row['photo_id']] ?? null),
            );
        }

        return [
            // Taken once everything is read - every answer is also a clock sample for the page
            'serverNowMs' => RoundClock::milliseconds($this->clock->now()),
            'asOfMs' => RoundClock::milliseconds($asOf),
            'round' => self::round($round, $clock),
            'presenceAvailable' => $presenceAvailable,
            'participants' => $participants,
            'mercure' => $this->officialResultsSubscription->forOnlineParticipant($roundId),
        ];
    }

    /**
     * The round's clock in the shape round_state.js reads (readClock(), adoptRoundClock())
     *
     * @return array<string, mixed>
     */
    public static function round(CompetitionRound $round, RoundClock $clock): array
    {
        return [
            'id' => $round->id->toString(),
            'name' => $round->name,
            'minutesLimit' => $clock->minutesLimit,
            'status' => $clock->status,
            'stopwatchStatus' => $clock->stopwatchStatus,
            'startedAtMs' => $clock->startedAtMs,
            'stoppedAtMs' => $clock->stoppedAtMs,
            'endsAtMs' => $clock->endsAtMs,
            'stateHash' => $clock->stateHash(),
        ];
    }

    /**
     * @param list<string> $mediaIds
     * @return array<string, string> media id => URL
     */
    private function photoUrls(array $mediaIds): array
    {
        $urls = [];

        foreach ($this->getOnlineLiveParticipants->latestPhotos($mediaIds) as $mediaId => $media) {
            try {
                $urls[$mediaId] = $this->onlineMediaStorage->presignGet($media, self::PHOTO_URL_SECONDS);
            } catch (\Throwable $exception) {
                // A store that can not be signed for (its configuration) - the grid shows the tile without its photo
                $this->logger->warning('Signing an online photo for the control room failed', [
                    'media_id' => $mediaId,
                    'exception' => $exception,
                ]);
            }
        }

        return $urls;
    }

    /**
     * The heartbeat as the presence keeps it (OnlineHeartbeat's telemetry + lastSeenMs), types checked again: the
     * presence is shared storage, the grid must never break on a value
     *
     * @param array<string, mixed> $beat
     * @return array{lastSeenMs: null|int, deviceId: null|string, visible: null|bool, camera: null|array{ok: null|bool, w: null|int, h: null|int, fps: null|float}, clock: null|array{offsetMs: null|float, rttMs: null|float}, outbox: null|array{events: null|int, media: null|int}}
     */
    private static function presence(array $beat): array
    {
        $camera = is_array($beat['camera'] ?? null) ? $beat['camera'] : null;
        $clock = is_array($beat['clock'] ?? null) ? $beat['clock'] : null;
        $outbox = is_array($beat['outbox'] ?? null) ? $beat['outbox'] : null;

        return [
            'lastSeenMs' => self::integer($beat['lastSeenMs'] ?? null),
            'deviceId' => is_string($beat['deviceId'] ?? null) ? $beat['deviceId'] : null,
            'visible' => is_bool($beat['visible'] ?? null) ? $beat['visible'] : null,
            'camera' => $camera === null ? null : [
                'ok' => is_bool($camera['ok'] ?? null) ? $camera['ok'] : null,
                'w' => self::integer($camera['w'] ?? null),
                'h' => self::integer($camera['h'] ?? null),
                'fps' => self::number($camera['fps'] ?? null),
            ],
            'clock' => $clock === null ? null : [
                'offsetMs' => self::number($clock['offsetMs'] ?? null),
                'rttMs' => self::number($clock['rttMs'] ?? null),
            ],
            'outbox' => $outbox === null ? null : [
                'events' => self::integer($outbox['events'] ?? null),
                'media' => self::integer($outbox['media'] ?? null),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $beat
     * @return null|array{deviceId: null|string, lastSeenMs: null|int}
     */
    private static function otherDevice(array $beat): null|array
    {
        $other = is_array($beat['otherDevice'] ?? null) ? $beat['otherDevice'] : null;

        return $other === null ? null : [
            'deviceId' => is_string($other['deviceId'] ?? null) ? $other['deviceId'] : null,
            'lastSeenMs' => self::integer($other['lastSeenMs'] ?? null),
        ];
    }

    private static function integer(mixed $value): null|int
    {
        return is_int($value) || (is_float($value) && is_finite($value)) ? (int) $value : null;
    }

    private static function number(mixed $value): null|float
    {
        return is_int($value) || (is_float($value) && is_finite($value)) ? (float) $value : null;
    }
}
