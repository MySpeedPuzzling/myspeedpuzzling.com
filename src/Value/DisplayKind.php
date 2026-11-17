<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a competition display is for (docs/features/competitions-management/toolkit/D-displays-overlays.md §2): a hall
 * screen (TV / projector - opaque, fills the viewport) or a stream overlay (OBS - transparent, 1920×1080, no UI).
 * Both share one entity, one editor and one renderer; the kind never changes after the display is added.
 */
enum DisplayKind: string
{
    case Screen = 'screen';
    case Overlay = 'overlay';
}
