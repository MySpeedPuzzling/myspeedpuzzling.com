<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Valid;

/**
 * The review of a merge request (PuzzleMergeReviewFormType): which reported puzzle keeps its address, and the merged
 * puzzle's record - every name of all of them in the names editor, brand, pieces, codes, image.
 */
final class PuzzleMergeReviewFormData
{
    #[NotBlank]
    public null|string $survivorPuzzleId = null;

    #[NotBlank]
    #[Positive]
    public null|int $piecesCount = null;

    // Null = the survivor's own brand
    public null|string $manufacturerId = null;

    #[Length(max: 100)]
    public null|string $ean = null;

    #[Length(max: 100)]
    public null|string $identificationNumber = null;

    // Asked for only when more of the puzzles have an image
    public null|string $selectedImagePuzzleId = null;

    // Why - kept in the puzzle's history
    #[Length(max: 2000)]
    public null|string $decisionNote = null;

    /**
     * Puzzle id => the PuzzleRecordVersion each reported puzzle was shown with
     *
     * @var array<string, string>
     */
    public array $recordVersions = [];

    public function __construct(
        #[Valid]
        public PuzzleNamesFormData $names,
    ) {
    }
}
