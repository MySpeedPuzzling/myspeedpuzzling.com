<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The official result an organiser recorded for one round entry (docs/features/competitions-management/official-results.md):
 * finished in `seconds`, did not finish with `piecesPlaced` pieces placed when time ran out, or did not start - at most
 * one of them; none of them = no result yet.
 *
 * Wire format (JSON, the official results endpoints and Mercure updates): null for no result, else an object with
 * exactly one key - {"seconds": 5025}, {"piecesPlaced": 479} or {"didNotStart": true}.
 */
final readonly class RoundEntryResult
{
    public const int MAX_SECONDS = 86399;

    private function __construct(
        public null|int $seconds,
        public null|int $piecesPlaced,
        public bool $didNotStart,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, false);
    }

    public static function finished(int $seconds): self
    {
        return new self($seconds, null, false);
    }

    public static function unfinished(int $piecesPlaced): self
    {
        return new self(null, $piecesPlaced, false);
    }

    public static function didNotStart(): self
    {
        return new self(null, null, true);
    }

    /**
     * From the entity's columns - whatever is stored, the first of seconds, pieces placed, did not start wins, so the
     * value always holds at most one of them.
     */
    public static function fromColumns(null|int $seconds, null|int $piecesPlaced, bool $didNotStart): self
    {
        if ($seconds !== null) {
            return self::finished($seconds);
        }

        if ($piecesPlaced !== null) {
            return self::unfinished($piecesPlaced);
        }

        return $didNotStart ? self::didNotStart() : self::none();
    }

    /**
     * The wire format. Shape only - ranges are the write path's business (RecordRoundResultsHandler).
     *
     * @throws \InvalidArgumentException
     */
    public static function fromWire(mixed $value): self
    {
        if ($value === null) {
            return self::none();
        }

        if (!is_array($value) || count($value) !== 1) {
            throw new \InvalidArgumentException('A result is null or an object with exactly one key.');
        }

        if (array_key_exists('seconds', $value) && is_int($value['seconds'])) {
            return self::finished($value['seconds']);
        }

        if (array_key_exists('piecesPlaced', $value) && is_int($value['piecesPlaced'])) {
            return self::unfinished($value['piecesPlaced']);
        }

        if (($value['didNotStart'] ?? null) === true) {
            return self::didNotStart();
        }

        throw new \InvalidArgumentException('Unknown result.');
    }

    /**
     * @return null|array{seconds: int}|array{piecesPlaced: int}|array{didNotStart: true}
     */
    public function toWire(): null|array
    {
        if ($this->seconds !== null) {
            return ['seconds' => $this->seconds];
        }

        if ($this->piecesPlaced !== null) {
            return ['piecesPlaced' => $this->piecesPlaced];
        }

        return $this->didNotStart ? ['didNotStart' => true] : null;
    }

    public function isNone(): bool
    {
        return $this->seconds === null && $this->piecesPlaced === null && $this->didNotStart === false;
    }

    public function isFinished(): bool
    {
        return $this->seconds !== null;
    }

    public function isUnfinished(): bool
    {
        return $this->piecesPlaced !== null;
    }

    /**
     * A result that takes part in the ranking: finished or unfinished. Did not start and no result are unranked.
     */
    public function isRanked(): bool
    {
        return $this->isFinished() || $this->isUnfinished();
    }

    public function equals(self $other): bool
    {
        return $this->seconds === $other->seconds
            && $this->piecesPlaced === $other->piecesPlaced
            && $this->didNotStart === $other->didNotStart;
    }
}
