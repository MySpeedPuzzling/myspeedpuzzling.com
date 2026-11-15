<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DataImport\ImportPersonChoice;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonDecidedHow;

/**
 * One person of an import's file as "People in your file" shows them (docs/features/data-import/people.md).
 */
readonly final class ImportPersonCard
{
    /**
     * @param list<string> $exampleRowIds
     * @param list<ImportPersonSuggestion> $suggestions
     */
    public function __construct(
        public string $personId,
        public string $personKey,
        public string $name,
        public int $rowsCount,
        public bool $writtenAsPlayer,
        /** What a row added now would take: `Player` only while that player may still be added */
        public ImportPersonChoice $choice,
        public ImportPersonDecidedHow $decidedHow,
        public null|string $playerId,
        /** Null for a private player the importing player may not see by name */
        public null|string $playerName,
        public null|string $playerCode,
        public array $exampleRowIds,
        public array $suggestions,
        /** The importing player has pair/team results with a guest of this name - the consent flow can be offered */
        public bool $isGuestOfTheirs,
    ) {
    }

    public function isPlayer(): bool
    {
        return $this->choice === ImportPersonChoice::Player && $this->playerId !== null;
    }

    public function isRemembered(): bool
    {
        return $this->decidedHow === ImportPersonDecidedHow::Remembered;
    }
}
