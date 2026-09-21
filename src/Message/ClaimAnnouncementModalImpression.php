<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\AnnouncementModal;

/**
 * Handled synchronously; the handler answers whether this request is the one that may show the modal.
 */
readonly final class ClaimAnnouncementModalImpression
{
    public function __construct(
        public string $playerId,
        public AnnouncementModal $modal,
    ) {
    }
}
