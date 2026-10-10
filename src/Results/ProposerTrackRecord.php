<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What a moderator sees next to a request (docs/features/karma.md, "Review queues"): the proposer's karma and how
 * many of their decided proposals were accepted. Nothing decided yet = "First suggestion", never a low number.
 */
readonly final class ProposerTrackRecord
{
    public function __construct(
        public int $karma,
        public int $accepted,
        public int $declined,
    ) {
    }

    public function decided(): int
    {
        return $this->accepted + $this->declined;
    }

    public function isFirstSuggestion(): bool
    {
        return $this->decided() === 0;
    }

    /** Whole percent of the decided proposals that were accepted, null while nothing was decided */
    public function acceptancePercent(): null|int
    {
        if ($this->decided() === 0) {
            return null;
        }

        return (int) floor(100 * $this->accepted / $this->decided());
    }
}
