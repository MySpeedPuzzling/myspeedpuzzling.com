<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AddFeatureRequestComment
{
    public function __construct(
        public string $authorId,
        public string $featureRequestId,
        public string $content,
    ) {
    }
}
