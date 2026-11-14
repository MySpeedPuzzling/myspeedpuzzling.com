<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * The decisions of DurationColumnResolver for one time column - one per ambiguous shape the column holds.
 */
final readonly class DurationColumnResolution
{
    /**
     * @param list<DurationShapeDecision> $decisions
     */
    public function __construct(
        public array $decisions,
    ) {
    }

    public function decisionFor(DurationShape $shape): null|DurationShapeDecision
    {
        foreach ($this->decisions as $decision) {
            if ($decision->shape === $shape) {
                return $decision;
            }
        }

        return null;
    }

    /**
     * @return list<DurationShapeDecision> The shapes the player has to answer
     */
    public function questions(): array
    {
        return array_values(array_filter(
            $this->decisions,
            static fn (DurationShapeDecision $decision): bool => $decision->isResolved() === false,
        ));
    }

    /**
     * A value of the column as the resolved shapes read it; a value whose shape waits for the player's answer, or has
     * none, stays as it is.
     */
    public function apply(ReadDuration $read): ReadDuration
    {
        $rule = $read->shape === null ? null : $this->decisionFor($read->shape)?->rule;

        return $rule === null ? $read : $read->settledBy($rule);
    }
}
