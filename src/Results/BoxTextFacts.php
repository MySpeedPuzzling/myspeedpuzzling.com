<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\BoxTextCatalogue;

/**
 * The current `box_text` analysis of a puzzle next to its record (GetBoxTextFacts).
 */
readonly final class BoxTextFacts
{
    /**
     * @param array<mixed> $boxText
     */
    public function __construct(
        public string $puzzleId,
        public array $boxText,
        public BoxTextCatalogue $catalogue,
        public DateTimeImmutable $analysisReceivedAt,
    ) {
    }
}
