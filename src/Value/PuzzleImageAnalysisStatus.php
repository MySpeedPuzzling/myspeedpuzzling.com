<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which puzzles the image list (GET /internal-api/puzzle-images) gives, for one analyzer: `missing` - no analysis of
 * it yet; `stale` - none, or one of another picture (imageFingerprint), or of another analyzer version (when a version
 * is asked for); `all` - every puzzle with a shown image.
 */
enum PuzzleImageAnalysisStatus: string
{
    case Missing = 'missing';
    case Stale = 'stale';
    case All = 'all';
}
