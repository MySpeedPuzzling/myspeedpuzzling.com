<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Compares every puzzle's box (its current `box_text` analysis) with its record and rewrites box_text_mismatch
 * (docs/features/puzzle-approvals.md, "Box-text mismatches") - myspeedpuzzling:detect-box-text-mismatches, daily.
 * Answers a BoxTextDetectionOutcome.
 */
readonly final class DetectBoxTextMismatches
{
}
