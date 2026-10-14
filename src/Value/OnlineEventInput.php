<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One event as a participant's phone sent it (`POST /api/compete/{token}/events`, docs/features/online-competitions/
 * implementation-plan.md §3.1), read and checked before anything is stored - pure, no database. The phone is treated
 * as a possibly hostile client:
 * - its own id - a canonical UUID (CanonicalUuid), the server's primary key, never made here; a known type (else
 *   `unknown_type`);
 * - `clientMs` = the moment on the phone's synced clock, whole epoch milliseconds; `perfMs`, `offsetMs`, `rttMs` =
 *   the raw clock values it was computed with (numbers or null);
 * - `roundId` = the round it belongs to - the request's round when it names none;
 * - `data` = an object of at most OnlineEntryQuota::MAX_EVENT_DATA_BYTES as JSON (a `media.uploaded` event's manifest
 *   apart: at most OnlineEntryQuota::MAX_MANIFEST_FRAMES frames), no NUL in any key or text (Postgres refuses it), with
 *   what its type needs: `timeup.pieces` a piece count (`pieces`, optional `measure` left / placed), `media.uploaded`
 *   the media id (`mediaId`, optional `bytes` and a burst's `manifest` of frames), `finish.*` optional photo / burst
 *   media ids, `finish.candidate` (Phase 2) its finish machine's numbers, view and mode (isCandidate()),
 *   `detector.changed` (stream P2F) whether the detector watches the round and why not (isDetectorChange()),
 *   `page.reloaded` (stream RP) how long the page was gone - whole milliseconds, null when the device does not know
 *   (isReload()).
 * Anything else wrong is `invalid_fields`. RecordOnlineEntryEventsHandler decides the rest (seen before, the round, the
 * participant's entry, the caps, the side effects).
 */
final readonly class OnlineEventInput
{
    public const string UNKNOWN_TYPE = 'unknown_type';
    public const string INVALID_FIELDS = 'invalid_fields';

    // Plausible epoch milliseconds (2001-09-09 … 2286-11-20) - seconds or a performance.now() value never pass
    public const int MIN_CLIENT_MS = 1_000_000_000_000;
    public const int MAX_CLIENT_MS = 9_999_999_999_999;
    public const int MAX_PIECES = 100_000;
    // An id echoed back in a refusal - never more than this of what a client sent
    private const int MAX_ECHOED_ID_LENGTH = 64;
    // A finish candidate's numbers (the finish machine's: moments on the synced clock, the hold, the scores, the frames)
    private const array CANDIDATE_NUMBERS = [
        'candidateId', 'detectedAtMs', 'windowFromMs', 'windowToMs', 'heldMs', 'score', 'minScore', 'peakScore',
        'upFrames', 'droppedFrames', 'machineVersion', 'fps', 'inferenceMs',
    ];
    public const array CANDIDATE_VIEWS = ['angled', 'topDown'];
    public const array CANDIDATE_MODES = ['off', 'shadow', 'on'];
    // Why a phone's finish detector does not watch the round (assets/online/compete_detection.js DETECTOR_REASONS)
    public const array DETECTOR_REASONS = ['off', 'loading', 'unsupported', 'error', 'stalled', 'slow', 'failed', 'skipped'];

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        // Lower case
        public string $id,
        public OnlineEventType $type,
        // Lower case
        public string $roundId,
        public int $clientMs,
        public null|float $perfMs,
        public null|float $offsetMs,
        public null|float $rttMs,
        public array $data,
    ) {
    }

    /**
     * @return self|array{id: null|string, reason: string} the event, or the refusal to answer for it
     */
    public static function read(mixed $event, string $defaultRoundId): self|array
    {
        if (!is_array($event)) {
            return self::refusal(null, self::INVALID_FIELDS);
        }

        $id = $event['id'] ?? null;

        if (!CanonicalUuid::isValid($id)) {
            return self::refusal(is_string($id) ? mb_substr($id, 0, self::MAX_ECHOED_ID_LENGTH) : null, self::INVALID_FIELDS);
        }

        $id = strtolower($id);
        $type = is_string($event['type'] ?? null) ? OnlineEventType::tryFrom($event['type']) : null;

        if ($type === null) {
            return self::refusal($id, self::UNKNOWN_TYPE);
        }

        $roundId = $event['roundId'] ?? $defaultRoundId;
        $clientMs = self::integer($event['clientMs'] ?? null);
        $data = self::data($type, $event['data'] ?? []);

        if (
            !CanonicalUuid::isValid($roundId)
            || $clientMs === null
            || $clientMs < self::MIN_CLIENT_MS
            || $clientMs > self::MAX_CLIENT_MS
            || $data === null
            || !self::isOptionalNumber($event['perfMs'] ?? null)
            || !self::isOptionalNumber($event['offsetMs'] ?? null)
            || !self::isOptionalNumber($event['rttMs'] ?? null)
        ) {
            return self::refusal($id, self::INVALID_FIELDS);
        }

        return new self(
            id: $id,
            type: $type,
            roundId: strtolower($roundId),
            clientMs: $clientMs,
            perfMs: self::number($event['perfMs'] ?? null),
            offsetMs: self::number($event['offsetMs'] ?? null),
            rttMs: self::number($event['rttMs'] ?? null),
            data: $data,
        );
    }

    /**
     * `timeup.pieces`: the pieces the participant reported
     */
    public function pieces(): null|int
    {
        return self::integer($this->data['pieces'] ?? null);
    }

    /**
     * `timeup.pieces`: the measure the participant counted in - null = the round's
     */
    public function measure(): null|OnlineUnfinishedMeasure
    {
        $measure = $this->data['measure'] ?? null;

        return is_string($measure) ? OnlineUnfinishedMeasure::tryFrom($measure) : null;
    }

    /**
     * `media.uploaded`: the uploaded media (lower case)
     */
    public function mediaId(): null|string
    {
        $mediaId = $this->data['mediaId'] ?? null;

        return is_string($mediaId) ? strtolower($mediaId) : null;
    }

    /**
     * `media.uploaded`: a burst's frames in the one uploaded object
     *
     * @return null|list<array{offset: int, length: int, captureMs: int|float}>
     */
    public function manifest(): null|array
    {
        $manifest = $this->data['manifest'] ?? null;

        return is_array($manifest) ? self::frames($manifest) : null;
    }

    /**
     * @return array<string, mixed>|null the data, null when it is not what the type needs
     */
    private static function data(OnlineEventType $type, mixed $data): null|array
    {
        // `{}` decodes to an empty array; a non-empty list is no object
        if (!is_array($data) || ($data !== [] && array_is_list($data)) || self::containsNul($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $measured = $data;

        // A burst's manifest has its own bound (frames)
        if ($type === OnlineEventType::MediaUploaded) {
            unset($measured['manifest']);
        }

        $encoded = json_encode($measured, JSON_PRESERVE_ZERO_FRACTION);

        if ($encoded === false || strlen($encoded) > OnlineEntryQuota::MAX_EVENT_DATA_BYTES) {
            return null;
        }

        $valid = match ($type) {
            OnlineEventType::TimeUpPieces => self::isPieceCount($data['pieces'] ?? null)
                && (($data['measure'] ?? null) === null || (is_string($data['measure']) && OnlineUnfinishedMeasure::tryFrom($data['measure']) !== null)),
            OnlineEventType::MediaUploaded => CanonicalUuid::isValid($data['mediaId'] ?? null)
                && (($data['bytes'] ?? null) === null || (self::integer($data['bytes']) ?? -1) >= 0)
                && (($data['manifest'] ?? null) === null || (is_array($data['manifest']) && self::frames($data['manifest']) !== null)),
            OnlineEventType::FinishButton => self::isOptionalUuid($data['photoMediaId'] ?? null)
                && self::isOptionalUuid($data['burstMediaId'] ?? null),
            OnlineEventType::FinishCandidate => self::isOptionalUuid($data['photoMediaId'] ?? null)
                && self::isOptionalUuid($data['burstMediaId'] ?? null)
                && self::isCandidate($data),
            OnlineEventType::DetectorChanged => self::isDetectorChange($data),
            OnlineEventType::PageReloaded => self::isReload($data),
            default => true,
        };

        return $valid ? $data : null;
    }

    /**
     * `finish.candidate` (Phase 2, assets/online/compete_detection.js candidateData()): what the finish machine said about
     * the raise - every field optional, but those present must be what they claim: CANDIDATE_NUMBERS numbers (null
     * allowed), `straddled` a boolean, `view` one of CANDIDATE_VIEWS, `mode` one of CANDIDATE_MODES
     *
     * @param array<string, mixed> $data
     */
    private static function isCandidate(array $data): bool
    {
        foreach (self::CANDIDATE_NUMBERS as $field) {
            if (!self::isOptionalNumber($data[$field] ?? null)) {
                return false;
            }
        }

        $straddled = $data['straddled'] ?? null;
        $view = $data['view'] ?? null;
        $mode = $data['mode'] ?? null;

        return ($straddled === null || is_bool($straddled))
            && ($view === null || in_array($view, self::CANDIDATE_VIEWS, true))
            && ($mode === null || in_array($mode, self::CANDIDATE_MODES, true));
    }

    /**
     * `detector.changed` (stream P2F, assets/online/compete_detection.js detectorUsage()): `running` a boolean, `state`
     * one of OnlineHeartbeat::DETECTOR_STATES; `reason` (null while it runs) one of DETECTOR_REASONS, `view` (null when
     * it does not run) one of CANDIDATE_VIEWS
     *
     * @param array<string, mixed> $data
     */
    private static function isDetectorChange(array $data): bool
    {
        $reason = $data['reason'] ?? null;
        $view = $data['view'] ?? null;

        return is_bool($data['running'] ?? null)
            && in_array($data['state'] ?? null, OnlineHeartbeat::DETECTOR_STATES, true)
            && ($reason === null || in_array($reason, self::DETECTOR_REASONS, true))
            && ($view === null || in_array($view, self::CANDIDATE_VIEWS, true));
    }

    /**
     * `page.reloaded` (stream RP, assets/online/compete_flow.js goneSince()): `goneMs` - how long the page was gone, a
     * whole number of milliseconds from 0, or null when the device does not know; `screen` - the screen it came back
     * to, a text
     *
     * @param array<string, mixed> $data
     */
    private static function isReload(array $data): bool
    {
        $goneMs = $data['goneMs'] ?? null;
        $screen = $data['screen'] ?? null;

        return ($goneMs === null || (self::integer($goneMs) ?? -1) >= 0)
            && ($screen === null || is_string($screen));
    }

    /**
     * @param array<mixed> $value
     */
    private static function containsNul(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && str_contains($key, "\0")) {
                return true;
            }

            if ((is_string($item) && str_contains($item, "\0")) || (is_array($item) && self::containsNul($item))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $manifest
     * @return null|list<array{offset: int, length: int, captureMs: int|float}>
     */
    private static function frames(array $manifest): null|array
    {
        if (!array_is_list($manifest) || count($manifest) > OnlineEntryQuota::MAX_MANIFEST_FRAMES) {
            return null;
        }

        $frames = [];

        foreach ($manifest as $frame) {
            if (!is_array($frame)) {
                return null;
            }

            $offset = self::integer($frame['offset'] ?? null);
            $length = self::integer($frame['length'] ?? null);
            $captureMs = $frame['captureMs'] ?? null;

            if ($offset === null || $offset < 0 || $length === null || $length < 1 || !self::isNumber($captureMs)) {
                return null;
            }

            /** @var int|float $captureMs */
            $frames[] = ['offset' => $offset, 'length' => $length, 'captureMs' => $captureMs];
        }

        return $frames;
    }

    /**
     * @return array{id: null|string, reason: string}
     */
    private static function refusal(null|string $id, string $reason): array
    {
        return ['id' => $id, 'reason' => $reason];
    }

    private static function isPieceCount(mixed $value): bool
    {
        $pieces = self::integer($value);

        return $pieces !== null && $pieces >= 0 && $pieces <= self::MAX_PIECES;
    }

    private static function isOptionalUuid(mixed $value): bool
    {
        return $value === null || CanonicalUuid::isValid($value);
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    private static function isOptionalNumber(mixed $value): bool
    {
        return $value === null || self::isNumber($value);
    }

    private static function number(mixed $value): null|float
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return (float) $value;
        }

        return null;
    }

    /**
     * A whole number - JSON may carry one as `12.0`
     */
    private static function integer(mixed $value): null|int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53) {
            return (int) $value;
        }

        return null;
    }
}
