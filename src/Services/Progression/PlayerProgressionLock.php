<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Progression;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Serializes every write of a player's progression - the XP ledger with player.xp_total / level, the achievements
 * with player.achievement_points - by locking the player rows until the transaction ends (the messenger
 * doctrine_transaction middleware commits). Two consumers working on the same player (an award next to a rebuild,
 * two quick saves of one week, the settlement cron) otherwise both read the same total and one update is lost, or
 * both hand the same weekly slot out.
 *
 * Take it BEFORE the player is loaded or the ledger is read: the totals read after it are the committed ones.
 * Several players are locked in one statement in id order, so two transactions never wait for each other in a
 * circle. Within a transaction it is re-entrant (a row lock the transaction already holds).
 */
readonly class PlayerProgressionLock
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $playerIds
     */
    public function lock(array $playerIds): void
    {
        $playerIds = array_values(array_unique($playerIds));

        if ($playerIds === []) {
            return;
        }

        $this->database->executeQuery(
            'SELECT id FROM player WHERE id IN (:playerIds) ORDER BY id FOR UPDATE',
            ['playerIds' => $playerIds],
            ['playerIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();
    }
}
