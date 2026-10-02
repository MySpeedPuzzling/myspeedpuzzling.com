<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ResultReviewEmailPreviewVariant;

readonly final class SendResultReviewEmailPreview
{
    public function __construct(
        public string $emailAddress,
        public string $locale,
        public ResultReviewEmailPreviewVariant $variant,
    ) {
    }
}
