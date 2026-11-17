<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The backfill path's puzzles step: ROWS rows, the first shown, the others behind "+ another puzzle"
 */
final class PastPuzzlesFormData
{
    public const int ROWS = 6;

    /**
     * @var list<PastPuzzleRowFormData>
     */
    #[Assert\Valid]
    public array $rows = [];

    public function __construct()
    {
        for ($index = 0; $index < self::ROWS; $index++) {
            $this->rows[] = new PastPuzzleRowFormData();
        }
    }

    /**
     * @return array<int, PastPuzzleRowFormData> the rows with a puzzle, by their index
     */
    public function filled(): array
    {
        return array_filter($this->rows, static fn (PastPuzzleRowFormData $row): bool => $row->slot->hasPuzzle());
    }
}
