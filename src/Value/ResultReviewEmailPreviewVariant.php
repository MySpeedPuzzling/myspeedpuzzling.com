<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which "Your results" e-mail the preview shows (`myspeedpuzzling:send-result-review-email-preview --variant`).
 */
enum ResultReviewEmailPreviewVariant: string
{
    // The backlog e-mail: cases + a removal + a time awaiting verification
    case First = 'first';
    // The later e-mail: new cases + a removal
    case Weekly = 'weekly';
    // Only a removal, nothing to decide
    case Removed = 'removed';
    // Only times awaiting verification and a moderator's answer - nothing about duplicates
    case Verification = 'verification';
    // Only moderators' answers to "The time is correct"
    case Answered = 'answered';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $variant): string => $variant->value, self::cases());
    }
}
