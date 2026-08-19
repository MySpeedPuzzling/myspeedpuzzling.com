<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One active-filter chip of the puzzle picker: what to call it and which query
 * string reproduces the current criteria *without* it (the chip's × link).
 */
final readonly class PuzzlePickerActiveFilter
{
    /**
     * @param string $key Unique within one criteria, e.g. "source", "pieces:500", "brand:<uuid>"
     * @param string $type One of "source", "solved", "pieces", "brand", "lent", "predicted_max", "difficulty", "gap", "order"
     * @param string $translationKey Chip label; brand chips render the manufacturer name instead
     * @param array<string, int|string> $translationParameters
     * @param array<string, mixed> $queryParametersWithoutThis
     * @param null|string $value Raw value for label lookups (manufacturer id for brand chips, tier value for difficulty chips)
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $translationKey,
        public array $translationParameters,
        public array $queryParametersWithoutThis,
        public null|string $value = null,
    ) {
    }
}
