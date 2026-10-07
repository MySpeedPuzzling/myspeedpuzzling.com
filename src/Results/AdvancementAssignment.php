<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One qualified entry of an AdvancementPlan and the round it goes to.
 */
readonly final class AdvancementAssignment implements \JsonSerializable
{
    public function __construct(
        public int $seed,
        public string $sourceRoundId,
        public string $targetRoundId,
        public RoundResultEntry $entry,
        // The entry made in the target round - set once applied
        public null|string $createdEntryRef = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'seed' => $this->seed,
            'sourceRoundId' => $this->sourceRoundId,
            'targetRoundId' => $this->targetRoundId,
            'entry' => $this->entry,
            'createdEntry' => $this->createdEntryRef,
        ];
    }
}
