<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which "Your results" e-mail the preview shows (`myspeedpuzzling:send-result-review-email-preview --variant`).
 */
enum ResultReviewEmailPreviewVariant: string
{
    // The backlog e-mail: cases + a removal
    case First = 'first';
    // The later e-mail: new cases + a removal
    case Weekly = 'weekly';
    // Only a removal, nothing to decide
    case Removed = 'removed';
}
