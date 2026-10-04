<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PuzzleDuplicateCandidate
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $image,
        public null|string $ean,
        public null|string $identificationNumber,
        public null|string $manufacturerName,
        public bool $approved,
        public int $solvedTimes,
        public bool $sameEan,
        // Same brand as the new puzzle
        public bool $sameBrand = false,
        // A brand the new puzzle's new brand probably duplicates (GetPuzzleApprovals::brandSuggestions())
        public bool $suggestedBrand = false,
        // Trigram similarity of the names, 0..1
        public float $nameSimilarity = 0.0,
    ) {
    }

    /**
     * How likely it is the same product - the search only found it similar. One title is printed by many brands
     * ("Tropical Paradise" exists from five of them), so a name match from another brand is usually another puzzle.
     *
     * @return 'likely'|'possible'|'unlikely'
     */
    public function likelihood(): string
    {
        if ($this->sameEan) {
            return 'likely';
        }

        return $this->sameBrand || $this->suggestedBrand ? 'possible' : 'unlikely';
    }

    public function sameName(): bool
    {
        return $this->nameSimilarity >= 0.999;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        /**
         * @var array{
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     pieces_count: int,
         *     image: null|string,
         *     ean: null|string,
         *     identification_number: null|string,
         *     manufacturer_name: null|string,
         *     approved: bool,
         *     solved_times: int|string,
         *     same_ean: null|bool,
         *     same_brand?: null|bool,
         *     suggested_brand?: null|bool,
         *     name_similarity?: null|float|string,
         * } $row
         */
        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            piecesCount: $row['pieces_count'],
            image: $row['image'],
            ean: $row['ean'],
            identificationNumber: $row['identification_number'],
            manufacturerName: $row['manufacturer_name'],
            approved: $row['approved'],
            solvedTimes: (int) $row['solved_times'],
            sameEan: $row['same_ean'] === true,
            sameBrand: ($row['same_brand'] ?? null) === true,
            suggestedBrand: ($row['suggested_brand'] ?? null) === true,
            nameSimilarity: (float) ($row['name_similarity'] ?? 0),
        );
    }
}
