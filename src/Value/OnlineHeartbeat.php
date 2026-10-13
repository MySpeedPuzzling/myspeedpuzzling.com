<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A participant's phone's heartbeat, every 15 s (docs/features/online-competitions/implementation-plan.md §3.1):
 * `{deviceId, roundId, visible, camera: {ok, w, h, fps}, detector: {ok, fps, mode}, clock: {offsetMs, rttMs},
 * outbox: {events, media}}`. The device id is required (a non-empty string of at most MAX_DEVICE_ID_LENGTH
 * characters); the rest is telemetry: what has the right type is kept, anything else (or unknown) is dropped - the
 * presence (OnlinePresence) stores only these few fields, never what a phone made up.
 */
final readonly class OnlineHeartbeat
{
    public const int MAX_DEVICE_ID_LENGTH = 64;
    private const int MAX_MODE_LENGTH = 16;

    /**
     * @param array<string, mixed> $telemetry
     */
    private function __construct(
        public string $deviceId,
        public array $telemetry,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function read(array $body): null|self
    {
        $deviceId = self::deviceId($body['deviceId'] ?? null);

        if ($deviceId === null) {
            return null;
        }

        $roundId = $body['roundId'] ?? null;
        $camera = self::object($body['camera'] ?? null);
        $detector = self::object($body['detector'] ?? null);
        $clock = self::object($body['clock'] ?? null);
        $outbox = self::object($body['outbox'] ?? null);

        return new self($deviceId, [
            'deviceId' => $deviceId,
            'roundId' => is_string($roundId) && CanonicalUuid::isValid($roundId) ? strtolower($roundId) : null,
            'visible' => self::boolean($body['visible'] ?? null),
            'camera' => $camera === null ? null : [
                'ok' => self::boolean($camera['ok'] ?? null),
                'w' => self::count($camera['w'] ?? null),
                'h' => self::count($camera['h'] ?? null),
                'fps' => self::number($camera['fps'] ?? null),
            ],
            'detector' => $detector === null ? null : [
                'ok' => self::boolean($detector['ok'] ?? null),
                'fps' => self::number($detector['fps'] ?? null),
                'mode' => self::text($detector['mode'] ?? null, self::MAX_MODE_LENGTH),
            ],
            'clock' => $clock === null ? null : [
                'offsetMs' => self::number($clock['offsetMs'] ?? null),
                'rttMs' => self::number($clock['rttMs'] ?? null),
            ],
            'outbox' => $outbox === null ? null : [
                'events' => self::count($outbox['events'] ?? null),
                'media' => self::count($outbox['media'] ?? null),
            ],
        ]);
    }

    /**
     * A device id as the phones send it (the events' `deviceId` too) - null when it is none: empty, too long, not UTF-8
     * or with a control character (a NUL would make Postgres refuse the row)
     */
    public static function deviceId(mixed $deviceId): null|string
    {
        return self::text($deviceId, self::MAX_DEVICE_ID_LENGTH);
    }

    /**
     * A short text of the phone's - trimmed, printable UTF-8 only, at most $maxLength characters; null otherwise
     */
    private static function text(mixed $value, int $maxLength): null|string
    {
        if (!is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || mb_check_encoding($value, 'UTF-8') === false) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && mb_strlen($value) <= $maxLength ? $value : null;
    }

    /**
     * @return null|array<mixed>
     */
    private static function object(mixed $value): null|array
    {
        return is_array($value) && ($value === [] || !array_is_list($value)) ? $value : null;
    }

    private static function boolean(mixed $value): null|bool
    {
        return is_bool($value) ? $value : null;
    }

    private static function number(mixed $value): null|float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
    }

    private static function count(mixed $value): null|int
    {
        $number = self::number($value);

        return $number !== null && $number >= 0 && $number < 1_000_000_000 ? (int) $number : null;
    }
}
