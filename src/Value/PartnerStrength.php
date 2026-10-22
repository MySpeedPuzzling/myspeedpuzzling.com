<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The preset "Good at" chips of a partner finder listing (docs/features/partner-finder.md D3). Puzzlers may add custom
 * chips too - one that reads like a preset label in any locale becomes the preset (PartnerStrengthNormalizer).
 */
enum PartnerStrength: string
{
    case Sorting = 'sorting';
    case Edges = 'edges';
    case Sky = 'sky';
    case ColourBlocks = 'colour_blocks';
    case Patterns = 'patterns';
    case Text = 'text';
    case Faces = 'faces';
    case PieceShapes = 'piece_shapes';
    case StayingCalm = 'staying_calm';
    case SplittingWork = 'splitting_work';

    public function translationKey(): string
    {
        return 'partner_finder.strength.' . $this->value;
    }
}
