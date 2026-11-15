<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A distinct puzzle of an import as its pairing screens show it (docs/features/data-import/pairing-screens.md): what
 * the file says, what the rules said, what was decided - and the catalogue puzzles it could be. What the file says
 * is the player's own text: shown escaped, never put in a URL.
 */
readonly final class ImportPuzzleCard
{
    /**
     * @param list<ImportCataloguePuzzle> $candidates the best first; only puzzles that may be offered now (hidden,
     *        secret and other players' unapproved ones are left out, a merged puzzle is its survivor)
     */
    public function __construct(
        public string $importPuzzleId,
        public string $name,
        public null|string $brandText,
        public null|int $piecesCount,
        public int $rowsResults,
        public int $rowsOwned,
        public int $rowsWishList,
        /** An ImportPuzzleTier's value - null: the similar-name search has not come to it */
        public null|string $tier,
        /** An ImportPuzzleChoice's value */
        public string $choice,
        /** `player`, `remembered`, `automatic` - null: never decided */
        public null|string $decidedHow,
        public int $decisionVersion,
        public null|ImportCataloguePuzzle $decided,
        public array $candidates,
        /** The catalogue brand the file's brand text stands for - null: no brand, or one we do not know */
        public null|string $manufacturerId = null,
    ) {
    }

    public function rows(): int
    {
        return $this->rowsResults + $this->rowsOwned + $this->rowsWishList;
    }

    public function isDecided(): bool
    {
        return $this->choice !== 'undecided';
    }

    /**
     * The catalogue puzzle a card of a list shows: the decided one, else the best candidate.
     */
    public function shown(): null|ImportCataloguePuzzle
    {
        return $this->decided ?? $this->candidates[0] ?? null;
    }

    /**
     * The list its card is on (GetImportPuzzleScreen::GROUPS) - by what the rules said, so a card stays where it is
     * when it is decided.
     */
    public function group(): string
    {
        return match ($this->tier) {
            'found' => 'found',
            'probably' => 'probably',
            'choose' => 'choose',
            'none' => 'not-found',
            default => 'remembered',
        };
    }

    /**
     * Whether "Tick all I see here" may take it: undecided, one candidate to confirm, the same piece count.
     */
    public function canBeTickedInAGroup(): bool
    {
        $shown = $this->shown();

        return $this->isDecided() === false && $shown !== null && $shown->piecesDiffer === false;
    }
}
