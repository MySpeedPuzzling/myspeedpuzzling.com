<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The puzzles a merge request is about now. A request names its puzzles as a list of ids (what the reporter said,
 * never changed); another merge may have merged one of them meanwhile - its id then leads to the puzzle it was merged
 * into (puzzle_redirect, GetCurrentPuzzleIds), which is the same puzzle as far as this request is concerned. A puzzle
 * deleted without a merge leads nowhere.
 *
 * Fewer than two puzzles left = nothing to merge: the request is outdated (OutdatedPuzzleRequests).
 */
readonly final class MergeRequestPuzzles
{
    /**
     * @param list<string> $reportedIds Lower case
     * @param array<string, null|string> $currentIds Reported id => the puzzle it is now, null = gone
     */
    private function __construct(
        private array $reportedIds,
        private array $currentIds,
    ) {
    }

    /**
     * @param array<string> $reportedIds As the request holds them
     * @param array<string, null|string> $currentIds GetCurrentPuzzleIds::of() of them
     * @param array<string, string> $mergedNow Puzzle id => the puzzle it is merged into by the merge running right now -
     *                                         its redirects are not written yet
     */
    public static function resolve(array $reportedIds, array $currentIds, array $mergedNow = []): self
    {
        $reported = [];
        $current = [];
        $mergedNow = array_change_key_case(array_map(strtolower(...), $mergedNow), CASE_LOWER);
        $currentIds = array_change_key_case($currentIds, CASE_LOWER);

        foreach ($reportedIds as $reportedId) {
            $reportedId = strtolower($reportedId);

            if (array_key_exists($reportedId, $current)) {
                continue;
            }

            $currentId = $currentIds[$reportedId] ?? null;
            $currentId = $currentId !== null ? strtolower($currentId) : null;

            // Merged right now - either the reported puzzle itself, or the puzzle an older merge led it to
            if (isset($mergedNow[$reportedId])) {
                $currentId = $mergedNow[$reportedId];
            } elseif ($currentId !== null && isset($mergedNow[$currentId])) {
                $currentId = $mergedNow[$currentId];
            }

            $reported[] = $reportedId;
            $current[$reportedId] = $currentId;
        }

        return new self($reported, $current);
    }

    /**
     * The puzzles to merge now, each once, in the order the request names them.
     *
     * @return list<string>
     */
    public function currentIds(): array
    {
        return array_values(array_unique(array_filter($this->currentIds, static fn (null|string $id): bool => $id !== null)));
    }

    /**
     * Reported puzzles merged into another puzzle meanwhile: reported id => the puzzle it is now.
     *
     * @return array<string, string>
     */
    public function mergedMeanwhile(): array
    {
        $merged = [];

        foreach ($this->currentIds as $reportedId => $currentId) {
            if ($currentId !== null && $currentId !== $reportedId) {
                $merged[$reportedId] = $currentId;
            }
        }

        return $merged;
    }

    /**
     * Reported puzzles that no longer exist and were not merged into any.
     *
     * @return list<string>
     */
    public function gone(): array
    {
        return array_values(array_filter($this->reportedIds, fn (string $id): bool => $this->currentIds[$id] === null));
    }

    /**
     * Null while there are two puzzles or more to merge.
     */
    public function outdatedReason(): null|PuzzleReportOutdatedReason
    {
        if (count($this->currentIds()) >= 2) {
            return null;
        }

        return $this->gone() === [] ? PuzzleReportOutdatedReason::AlreadyMerged : PuzzleReportOutdatedReason::PuzzlesGone;
    }

    /**
     * The one puzzle left of an outdated request - what its puzzles became.
     */
    public function onlyCurrentId(): null|string
    {
        $currentIds = $this->currentIds();

        return count($currentIds) === 1 ? $currentIds[0] : null;
    }
}
