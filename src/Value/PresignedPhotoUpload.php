<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where the browser uploads one photo straight to object storage (DirectPhotoUpload, docs/features/photo-uploads/README.md):
 * a presigned `PUT` with the headers it was signed with (type and length), valid until `expiresAtMs`. `token` is what
 * the form sends in its place (`photo_stash[<field>]`).
 */
final readonly class PresignedPhotoUpload
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $token,
        public string $url,
        public array $headers,
        public int $expiresAtMs,
    ) {
    }
}
