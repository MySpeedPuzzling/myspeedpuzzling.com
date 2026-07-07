<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class DeletePageSection
{
    public function __construct(
        public string $sectionId,
    ) {
    }
}
