<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where and how a participant's phone uploads one evidence object straight to object storage
 * (OnlineMediaStorage::presignPut(), docs/features/online-competitions/README.md §5.11): a presigned `PUT` with the
 * headers it was signed with (type and length), valid until `expiresAtMs`, into `store`.
 */
final readonly class PresignedUpload
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $url,
        public array $headers,
        public int $expiresAtMs,
        public OnlineMediaStore $store,
    ) {
    }
}
