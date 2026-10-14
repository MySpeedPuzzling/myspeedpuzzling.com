<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What a gap mark (MarkOnlineMomentGap) or its removal (UnmarkOnlineMomentGap) did: whether anything changed - marking
 * a marked moment again, or "Not a gap" on a moment without a mark, changes nothing - and the participant's online entry
 * the moment belongs to.
 */
readonly final class OnlineMomentGapChange
{
    public function __construct(
        public bool $changed,
        public string $onlineEntryId,
    ) {
    }
}
