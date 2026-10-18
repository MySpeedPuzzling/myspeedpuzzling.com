<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\BoxTextCatalogue;
use SpeedPuzzling\Web\Value\BoxTextField;

/**
 * One line of the moderators' box-text mismatch list (GetBoxTextMismatches) - the catalogue as it is now.
 */
readonly final class BoxTextMismatchRow
{
    /**
     * @param list<string> $boxValues
     * @param list<string> $evidence
     */
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleImage,
        public BoxTextField $field,
        public string $valueKey,
        public array $boxValues,
        public array $evidence,
        public BoxTextCatalogue $catalogue,
        public int $solvedCount,
        public DateTimeImmutable $analysisReceivedAt,
        public DateTimeImmutable $detectedAt,
        // Hidden now (hide_until / hide_image_until ahead) - listed for admins only
        public bool $hidden,
    ) {
    }

    public function catalogueValue(): null|string
    {
        return $this->catalogue->valueOf($this->field);
    }
}
