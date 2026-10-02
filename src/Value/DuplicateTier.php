<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How sure we are that two results are the same result saved twice (docs/features/duplicate-results.md, "Definitions").
 */
enum DuplicateTier: string
{
    // A: removed automatically (reversible) and told
    case Certain = 'certain';
    // B: asked, the older copy preselected
    case Strong = 'strong';
    // C: asked, neutral wording, nothing preselected
    case Possible = 'possible';

    public function letter(): string
    {
        return match ($this) {
            self::Certain => 'A',
            self::Strong => 'B',
            self::Possible => 'C',
        };
    }
}
