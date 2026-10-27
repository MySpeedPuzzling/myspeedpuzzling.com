<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Deletes the finished-photo analyses whose photo is gone (removed, replaced, or its player went private) - daily,
 * myspeedpuzzling:prune-finished-photo-analyses. The handler answers the number of deleted rows.
 */
readonly final class PruneFinishedPhotoAnalyses
{
}
