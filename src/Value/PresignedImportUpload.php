<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where the browser uploads an import's file straight to object storage (ImportFileStorage): a presigned `PUT` with
 * the headers it was signed with (the server-chosen type and the exact length), valid until `expiresAtMs`.
 */
final readonly class PresignedImportUpload
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $url,
        public array $headers,
        public int $expiresAtMs,
    ) {
    }
}
