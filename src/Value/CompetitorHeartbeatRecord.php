<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the server last heard from a competitor's own page for one round entry (CompetitorHeartbeats, reliability
 * hardening T2): the last heartbeat and the last *clean* one - a heartbeat whose outbox held no unsent tap (nothing
 * waiting to be sent, nothing in the confirm sheet). Times are the server's milliseconds.
 *
 * - `lastHeardAtMs` / `lastOffsetMs`: the receipt of the last heartbeat and the clock offset the phone used then.
 * - `cleanAtMs`: the evidence moment of the last clean heartbeat - the phone's server-synced `atMs` when it built the
 *   beat, clamped to [receipt − 30 s, receipt] (CompetitorHeartbeat::evidenceAtMs()); `cleanHeardAtMs` its receipt.
 */
readonly final class CompetitorHeartbeatRecord
{
    public function __construct(
        public null|int $lastHeardAtMs = null,
        public null|float $lastOffsetMs = null,
        public null|int $cleanAtMs = null,
        public null|int $cleanHeardAtMs = null,
    ) {
    }

    /**
     * @param array<mixed> $last the last beat as stored (`heardAtMs`, `offsetMs`)
     * @param array<mixed> $clean the last clean beat as stored (`atMs`, `heardAtMs`)
     */
    public static function fromStored(array $last, array $clean): self
    {
        return new self(
            lastHeardAtMs: self::int($last['heardAtMs'] ?? null),
            lastOffsetMs: is_int($last['offsetMs'] ?? null) || is_float($last['offsetMs'] ?? null) ? (float) $last['offsetMs'] : null,
            cleanAtMs: self::int($clean['atMs'] ?? null),
            cleanHeardAtMs: self::int($clean['heardAtMs'] ?? null),
        );
    }

    private static function int(mixed $value): null|int
    {
        return is_int($value) ? $value : (is_float($value) && is_finite($value) ? (int) $value : null);
    }
}
