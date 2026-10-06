<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Valid;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

    /**
     * One input per code (CodeListType), prefilled with the codes of all the puzzles - not capped, the union may hold
     * more than a form adds; the codes of every merged puzzle are added on approval either way
     *
     * @var array<int, null|string>
     */
    #[All([new Length(max: 30)])]
    public array $eans = [];

    /**
     * @var array<int, null|string>
     */
    #[All([new Length(max: 50)])]
    public array $brandCodes = [];

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

    // The codes of all the reported puzzles (not a form field): they pass as they are, and the approval adds them back
    public null|string $knownEans = null;

    public null|string $knownBrandCodes = null;

    public function __construct(
        #[Valid]
        public PuzzleNamesFormData $names,
    ) {
    }

    /**
     * A code the reviewer adds follows the rule of every form (EanList::invalidCodes()); the merged lists - the inputs
     * plus every reported puzzle's codes, as the approval stores them - must fit their columns.
     */
    #[Callback]
    public function validateCodes(ExecutionContextInterface $context): void
    {
        foreach ($this->eans as $index => $input) {
            EanList::addViolations($context, sprintf('eans[%s]', $index), $input, $this->knownEans);
        }

        $columnMessage = (new Length(max: EanList::MAX_STORED_LENGTH))->maxMessage;

        foreach (
            [
            'eans' => EanList::fromInputs($this->eans)->union(EanList::fromStored($this->knownEans))->fitsColumn(),
            'brandCodes' => BrandCodeList::fromInputs($this->brandCodes)->union(BrandCodeList::fromStored($this->knownBrandCodes))->fitsColumn(),
            ] as $path => $fits
        ) {
            if ($fits === false) {
                $context->buildViolation($columnMessage)
                    ->setParameter('{{ limit }}', (string) EanList::MAX_STORED_LENGTH)
                    ->setPlural(EanList::MAX_STORED_LENGTH)
                    ->atPath($path)
                    ->addViolation();
            }
        }
    }
}
