<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\CollectionDisplayMode;

/**
 * The viewer's persisted choice for collection pages (README §5). Deliberately
 * not part of PlayerProfile: only the two collection detail pages and the POST
 * that changes it read this column, so no other page depends on it.
 */
readonly final class GetCollectionDisplayMode
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Off when the player does not exist (or the value is unknown).
     */
    public function forPlayer(string $playerId): CollectionDisplayMode
    {
        $value = $this->database->executeQuery(
            'SELECT collection_display_mode FROM player WHERE id = :playerId',
            ['playerId' => $playerId],
        )->fetchOne();

        if (!is_string($value)) {
            return CollectionDisplayMode::Off;
        }

        return CollectionDisplayMode::tryFrom($value) ?? CollectionDisplayMode::Off;
    }
}
