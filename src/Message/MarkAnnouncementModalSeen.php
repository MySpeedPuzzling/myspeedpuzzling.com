<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\AnnouncementModal;

readonly final class MarkAnnouncementModalSeen
{
    public function __construct(
        public string $playerId,
        public AnnouncementModal $modal,
    ) {
    }
}
