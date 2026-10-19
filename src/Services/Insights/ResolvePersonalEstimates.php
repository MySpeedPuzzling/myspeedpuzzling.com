<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Query\GetPersonalEstimates;
use SpeedPuzzling\Web\Results\CollectionDifficultyRank;
use SpeedPuzzling\Web\Results\PersonalEstimate;
use SpeedPuzzling\Web\Results\PersonalEstimates;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates\ImageEstimateGate;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\PiecesGroup;

/**
 * Who sees personal time estimates (option C, docs/features/insights/estimates.md) and the one call every surface
 * makes - the puzzle page, the collection-like lists, the Puzzle Picker cards and the marketplace cards:
 * - null while InsightsFeature hides Insights from the viewer (INSIGHTS_PUBLIC off, not an admin) or for a guest:
 *   the templates render exactly what they rendered before, and nothing is queried;
 * - locked for a signed-in player without an active membership (a free trial counts as one) - nothing queried;
 * - opted out for a member who switched time predictions off - nothing queried;
 * - else the estimates: one statement for the whole list (GetPersonalEstimates), plus the gate record once per request
 *   when a listed puzzle nobody has measured has a picture estimate (ImageEstimateGate - it fails closed: picture-only
 *   estimates show only for a size group × image kind whose gate passed, even after the flag is gone).
 * The viewer decides, never whoever the list belongs to: the times are always the viewer's own.
 */
readonly final class ResolvePersonalEstimates
{
    public function __construct(
        private InsightsFeature $insightsFeature,
        private GetPersonalEstimates $getPersonalEstimates,
        private ImageEstimateGate $imageEstimateGate,
    ) {
    }

    /**
     * @param array<string> $puzzleIds the puzzles on the page (duplicates are fine)
     */
    public function forViewer(null|PlayerProfile $viewer, array $puzzleIds): null|PersonalEstimates
    {
        if ($viewer === null || $this->insightsFeature->isVisibleTo($viewer) === false) {
            return null;
        }

        if ($viewer->activeMembership === false) {
            return PersonalEstimates::locked();
        }

        if ($viewer->timePredictionsOptedOut) {
            return PersonalEstimates::optedOut();
        }

        return PersonalEstimates::shown($this->getPersonalEstimates->forPuzzles(
            $viewer->playerId,
            $puzzleIds,
            fn (PiecesGroup $group, ImageKind $kind, string $generation): bool => $this->imageEstimateGate->passesFor($group, $kind, $generation),
        ));
    }

    /**
     * The puzzle page card's context line ("The 2nd-hardest 1000-piece puzzle in your collection") - one statement,
     * only for an estimate with a difficulty.
     *
     * @return null|array{hardest: bool, rank: int<1, 3>}
     */
    public function collectionContext(PlayerProfile $viewer, PersonalEstimate $estimate): null|array
    {
        if ($estimate->difficulty === null) {
            return null;
        }

        return $this->collectionRank($viewer, $estimate)->context();
    }

    private function collectionRank(PlayerProfile $viewer, PersonalEstimate $estimate): CollectionDifficultyRank
    {
        return $this->getPersonalEstimates->collectionRank($viewer->playerId, $estimate->puzzleId, $estimate->piecesCount, (float) $estimate->difficulty);
    }
}
