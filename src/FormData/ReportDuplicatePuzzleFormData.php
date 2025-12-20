<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;

final class ReportDuplicatePuzzleFormData
{
    /**
     * @var array<string>
     */
    #[Count(min: 1, max: 4, minMessage: 'Please select at least one duplicate puzzle.', maxMessage: 'You can report at most 4 duplicate puzzles (5 total including the current one).')]
    public array $duplicatePuzzleIds = [];

    #[Length(max: 1000)]
    public null|string $duplicatePuzzleUrls = null;

    public null|string $selectedManufacturerId = null;
}
