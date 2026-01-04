<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Callback('validateHasDuplicate')]
final class ReportDuplicatePuzzleFormData
{
    /**
     * @var array<string>
     */
    public array $duplicatePuzzleIds = [];

    #[Length(max: 500)]
    public null|string $duplicatePuzzleUrl = null;

    public null|string $selectedManufacturerId = null;

    public null|string $selectedPuzzleId = null;

    public function validateHasDuplicate(ExecutionContextInterface $context): void
    {
        $hasSelectedPuzzle = $this->selectedPuzzleId !== null && $this->selectedPuzzleId !== '';
        $hasUrl = $this->duplicatePuzzleUrl !== null && $this->duplicatePuzzleUrl !== '';

        if (!$hasSelectedPuzzle && !$hasUrl) {
            $context->buildViolation('Please select a duplicate puzzle or enter a puzzle URL.')
                ->atPath('selectedPuzzleId')
                ->addViolation();
        }
    }
}
