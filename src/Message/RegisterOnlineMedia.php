<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\OnlineMediaKind;

/**
 * An evidence object a participant's phone is about to upload (`POST /api/compete/{token}/media`,
 * docs/features/online-competitions/implementation-plan.md §3.1) - the row first, under an id the controller made, then
 * the controller presigns the upload (OnlineMediaStorage::presignPut()).
 */
readonly final class RegisterOnlineMedia
{
    public function __construct(
        public string $mediaId,
        public string $participantId,
        public string $roundId,
        public string $deviceId,
        public OnlineMediaKind $kind,
        // When the phone took it - epoch ms on its synced clock
        public int $takenMs,
        // The size the phone announced - the upload is signed for exactly this length
        public int $bytes,
        public string $contentType,
        // The phone's event it belongs to (a finish tap's photo and burst)
        public null|string $relatedEventId = null,
    ) {
    }
}
