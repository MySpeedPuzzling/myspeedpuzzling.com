<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Ramsey\Uuid\Uuid;

/**
 * One PhotoStash description (photo_stash_entry) - only the player's own. Expiry is decided by the caller
 * (PhotoStash, on the app's clock).
 */
readonly final class GetPhotoStashEntry
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return null|array{name: string, mime: string, preview: bool, bytes: null|int, storedAt: DateTimeImmutable, browserMetadata: null|array<mixed>}
     */
    public function of(string $token, string $playerId): null|array
    {
        if (Uuid::isValid($playerId) === false) {
            return null;
        }

        $query = <<<SQL
SELECT name, mime, preview, bytes, stored_at, browser_metadata
FROM photo_stash_entry
WHERE token = :token AND player_id = :playerId
SQL;

        /** @var false|array{name: string, mime: string, preview: bool, bytes: null|int, stored_at: string, browser_metadata: null|string} $row */
        $row = $this->database->executeQuery($query, [
            'token' => $token,
            'playerId' => $playerId,
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        try {
            $browserMetadata = $row['browser_metadata'] !== null ? Json::decode($row['browser_metadata'], forceArrays: true) : null;
        } catch (JsonException) {
            $browserMetadata = null;
        }

        return [
            'name' => $row['name'],
            'mime' => $row['mime'],
            'preview' => (bool) $row['preview'],
            'bytes' => $row['bytes'] !== null ? (int) $row['bytes'] : null,
            'storedAt' => new DateTimeImmutable($row['stored_at']),
            'browserMetadata' => is_array($browserMetadata) ? $browserMetadata : null,
        ];
    }
}
