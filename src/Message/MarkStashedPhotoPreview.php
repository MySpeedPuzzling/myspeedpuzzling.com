<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * A refused form showed a background-uploaded photo again and its preview is in object storage now - the photo is
 * recent again (the cap keeps it), its expiry does not move.
 *
 * Fresh entity manager: dispatched on the refused-form path, where a handler rolled back just before may have left
 * entities behind - this message's flush must not write them.
 */
readonly final class MarkStashedPhotoPreview implements RequiresFreshEntityManagerState
{
    /**
     * @param null|array<mixed> $browserMetadata kept only when the reservation came without one
     */
    public function __construct(
        public string $token,
        public string $playerId,
        public null|array $browserMetadata,
        public DateTimeImmutable $at,
    ) {
    }
}
