<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A group of tags a page shows together (PuzzleTaxonomy). Only the image traits have labels so far - what Insights
 * shows first (docs/features/insights/README.md "Image intelligence"); a new group gets its keys and their labels in
 * all 6 locales when a page first shows it.
 */
enum PuzzleTagGroup: string
{
    // What a picture is like to solve: large single-colour areas, gradients, busy / collage, text, dark, photo or not
    case ImageTraits = 'image_traits';
}
