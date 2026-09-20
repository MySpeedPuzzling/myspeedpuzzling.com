<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AllowPrivateProfileViewer
{
    public function __construct(
        public string $ownerId,
        public string $viewerId,
    ) {
    }
}
