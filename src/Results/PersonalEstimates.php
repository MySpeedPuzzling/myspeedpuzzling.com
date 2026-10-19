<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The personal time estimates of one page (ResolvePersonalEstimates, docs/features/insights/estimates.md) - only while
 * InsightsFeature shows Insights to the viewer; templates get null otherwise and render exactly what they did before.
 * - `locked`: a signed-in player without a membership - the lock ("≈ ? for you"), nothing queried;
 * - `optedOut`: a member who switched time predictions off - no times, nothing queried;
 * - else the estimates by puzzle id; a puzzle missing from them has none to show (secret, no data, no pace).
 */
readonly final class PersonalEstimates
{
    /**
     * @param array<string, PersonalEstimate> $byPuzzle
     */
    private function __construct(
        public bool $locked,
        public bool $optedOut,
        public array $byPuzzle,
    ) {
    }

    public static function locked(): self
    {
        return new self(locked: true, optedOut: false, byPuzzle: []);
    }

    public static function optedOut(): self
    {
        return new self(locked: false, optedOut: true, byPuzzle: []);
    }

    /**
     * @param array<string, PersonalEstimate> $byPuzzle
     */
    public static function shown(array $byPuzzle): self
    {
        return new self(locked: false, optedOut: false, byPuzzle: $byPuzzle);
    }

    public function of(string $puzzleId): null|PersonalEstimate
    {
        return $this->byPuzzle[$puzzleId] ?? null;
    }

    /**
     * The shown predictions (personal or statistical, the picture-only ones where they show) by puzzle id - for the
     * parts of a page that read a TimePredictionResult (the Picker card's "vs your best")
     *
     * @return array<string, TimePredictionResult>
     */
    public function predictions(): array
    {
        $predictions = [];

        foreach ($this->byPuzzle as $puzzleId => $estimate) {
            if ($estimate->prediction !== null) {
                $predictions[$puzzleId] = $estimate->prediction;
            }
        }

        return $predictions;
    }

    /**
     * The viewer gets times on this page (a member who has not switched predictions off) - the estimate line then
     * stands in for the collections' "+ predictions" pill
     */
    public function showsTimes(): bool
    {
        return $this->locked === false && $this->optedOut === false;
    }

    public function hasAny(): bool
    {
        return $this->byPuzzle !== [];
    }
}
