<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PartnerStrength;

/**
 * A listing's "Good at" chips after PartnerStrengthNormalizer: presets in the order picked, custom chips as typed
 * (trimmed, whitespace collapsed), none of them folding equal to a preset or to each other.
 */
readonly final class PartnerStrengthsNormalized
{
    public const int MAX_PRESETS = 4;
    public const int MAX_CUSTOM = 5;
    public const int MAX_CUSTOM_LENGTH = 30;

    /**
     * @param list<PartnerStrength> $presets
     * @param list<string> $custom
     */
    public function __construct(
        public array $presets = [],
        public array $custom = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function presetValues(): array
    {
        return array_map(static fn (PartnerStrength $strength): string => $strength->value, $this->presets);
    }
}
