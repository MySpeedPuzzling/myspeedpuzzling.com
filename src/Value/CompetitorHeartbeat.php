<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One heartbeat of a competitor's own page as the phone sent it (reliability hardening T2, CompetitorHeartbeatController):
 * the phone's server-synced clock when it built the beat (`atMs`) and what that clock rests on (`offsetMs`, `rttMs`,
 * `sampleAgeMs`), and its proposals outbox - `unsent` taps of the round still on the phone (a tap in the confirm sheet
 * counts) and the oldest one's tap moment.
 *
 * Clean = nothing unsent: the phone was online and held no tap. Only a clean beat is evidence against a tap made before
 * it (TimeProposalTiming) - its moment is the phone's `atMs`, never later than its receipt and never more than 30 s
 * before it (a request that hung on the way does not count as built later than it was).
 */
readonly final class CompetitorHeartbeat
{
    public const int MAX_BUILT_BEFORE_RECEIPT_MS = 30_000;

    public function __construct(
        public int $unsent,
        public null|int $oldestUnsentTapMs = null,
        public null|int $atMs = null,
        public null|float $offsetMs = null,
        public null|float $rttMs = null,
        public null|int $sampleAgeMs = null,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function read(array $body): null|self
    {
        $unsent = $body['unsent'] ?? null;

        if (!is_int($unsent) || $unsent < 0 || $unsent > 1000) {
            return null;
        }

        return new self(
            unsent: $unsent,
            oldestUnsentTapMs: self::int($body['oldestUnsentTapMs'] ?? null),
            atMs: self::int($body['atMs'] ?? null),
            offsetMs: self::float($body['offsetMs'] ?? null),
            rttMs: self::float($body['rttMs'] ?? null),
            sampleAgeMs: self::int($body['sampleAgeMs'] ?? null),
        );
    }

    public function isClean(): bool
    {
        return $this->unsent === 0;
    }

    public function evidenceAtMs(int $heardAtMs): int
    {
        if ($this->atMs === null) {
            return $heardAtMs;
        }

        return max($heardAtMs - self::MAX_BUILT_BEFORE_RECEIPT_MS, min($heardAtMs, $this->atMs));
    }

    /**
     * @return array<string, null|int|float>
     */
    public function stored(int $heardAtMs): array
    {
        return [
            'heardAtMs' => $heardAtMs,
            'atMs' => $this->atMs,
            'offsetMs' => $this->offsetMs,
            'rttMs' => $this->rttMs,
            'sampleAgeMs' => $this->sampleAgeMs,
            'unsent' => $this->unsent,
            'oldestUnsentTapMs' => $this->oldestUnsentTapMs,
        ];
    }

    /**
     * @return array{atMs: int, heardAtMs: int}
     */
    public function cleanRecord(int $heardAtMs): array
    {
        return ['atMs' => $this->evidenceAtMs($heardAtMs), 'heardAtMs' => $heardAtMs];
    }

    private static function int(mixed $value): null|int
    {
        if (is_float($value) && is_finite($value) && abs($value) < 1e15) {
            return (int) round($value);
        }

        return is_int($value) ? $value : null;
    }

    private static function float(mixed $value): null|float
    {
        return (is_int($value) || (is_float($value) && is_finite($value))) ? (float) $value : null;
    }
}
