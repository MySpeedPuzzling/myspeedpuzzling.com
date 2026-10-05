<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * An admin adds a puzzle to the catalogue - approved at once and **without a photo of its box**, the one way besides
 * a competition round's new puzzle (AddPuzzleToCompetitionRound) to add a puzzle without one. Players always add
 * through AddPuzzle, whose photo is required (PuzzleBoxPhoto); this is the internal API's (`POST
 * /internal-api/puzzles`), for competition puzzles found missing while an event is entered.
 */
readonly final class AddApprovedPuzzle
{
    public function __construct(
        public UuidInterface $puzzleId,
        // The admin adding it - recorded as who added and who approved it
        public string $reviewerId,
        public string $name,
        // A brand id, or a brand name: an existing brand of that name, else a new (unapproved) brand (ManufacturerResolver)
        public string $brand,
        public int $piecesCount,
        public EanList $eans,
        public BrandCodeList $brandCodes,
        // The main title's language when the box has no English title - null = English, or not known
        public null|string $nameLanguage = null,
        public PuzzleNames $alternativeNames = new PuzzleNames(),
    ) {
    }
}
