<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * A photo in the player's PhotoStash: kept for a refused form (`bytes` null - PHP already wrote it to object storage) or
 * a place reserved for a background upload (`bytes` = what the upload is signed for). The oldest beyond the cap of a few
 * per player make way (PhotoStash::MAX_PER_PLAYER). The token is the caller's - nothing is answered.
 *
 * Fresh entity manager: dispatched on the refused-form path, where a handler rolled back just before may have left
 * entities behind - this message's flush must not write them.
 */
readonly final class RecordPhotoStashEntry implements RequiresFreshEntityManagerState
{
    /**
     * @param null|array<mixed> $browserMetadata UploadedImageMetadata::toArray()
     */
    public function __construct(
        public string $token,
        public string $playerId,
        public string $name,
        public string $mime,
        public bool $preview,
        public null|int $bytes,
        public null|array $browserMetadata,
        public DateTimeImmutable $storedAt,
        public DateTimeImmutable $touchedAt,
    ) {
    }
}
