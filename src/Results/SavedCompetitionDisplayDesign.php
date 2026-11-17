<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What SaveCompetitionDisplayDesign stored - the editor adopts the normalised design and the new version.
 */
readonly final class SavedCompetitionDisplayDesign
{
    /**
     * @param array<string, mixed> $design
     */
    public function __construct(
        public int $designVersion,
        public array $design,
        public bool $usesResults,
        public bool $usesLiveResults,
        public bool $changed,
    ) {
    }
}
