<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetCollectionDisplayMode;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Query\GetPlayerPuzzleTimes;
use SpeedPuzzling\Web\Results\CollectionDisplay;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Value\CollectionDisplayMode;

/**
 * Shared by the system and custom collection detail pages: reads the viewer's
 * persisted display mode, applies the eligibility rules (predictions need an
 * active membership without the time-predictions opt-out - the same line as
 * the puzzle picker) and loads only what the effective mode shows. Guests get
 * Off without touching the database, so nothing here runs for them.
 */
readonly final class ResolveCollectionDisplay
{
    public function __construct(
        private GetCollectionDisplayMode $getCollectionDisplayMode,
        private GetPlayerPuzzleTimes $getPlayerPuzzleTimes,
        private GetPlayerPredictions $getPlayerPredictions,
    ) {
    }

    /**
     * @param array<string> $puzzleIds the puzzles listed on the page (duplicates are fine)
     */
    public function forViewer(null|PlayerProfile $viewer, array $puzzleIds): CollectionDisplay
    {
        if ($viewer === null) {
            return CollectionDisplay::off();
        }

        $mode = $this->getCollectionDisplayMode->forPlayer($viewer->playerId);

        if ($mode->showsPredictions() && self::predictionsAllowed($viewer) === false) {
            $mode = CollectionDisplayMode::Times;
        }

        if ($mode->showsTimes() === false || $puzzleIds === []) {
            return new CollectionDisplay($mode);
        }

        $puzzleIds = array_values(array_unique($puzzleIds));

        return new CollectionDisplay(
            mode: $mode,
            myTimes: $this->getPlayerPuzzleTimes->forPuzzles($viewer->playerId, $puzzleIds),
            predictions: $mode->showsPredictions()
                ? $this->getPlayerPredictions->forPuzzles($viewer->playerId, $puzzleIds)
                : [],
        );
    }

    /**
     * Members who have not opted out of time predictions - the eligibility
     * line of the picker's prediction row and the "+ predictions" option.
     */
    public static function predictionsAllowed(PlayerProfile $viewer): bool
    {
        return $viewer->activeMembership && $viewer->timePredictionsOptedOut === false;
    }
}
