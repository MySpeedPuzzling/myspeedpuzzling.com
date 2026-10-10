<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\LevelTable;
use SpeedPuzzling\Web\Value\XpEntryDraft;
use SpeedPuzzling\Web\Value\XpLevelChange;

/**
 * The only writer of the XP ledger (`xp_entry`) and of the denormalized Player.xpTotal / Player.level.
 *
 * Invariant: Player.xpTotal always equals SUM(xp_entry.amount) of that player, Player.level equals
 * LevelTable::levelForXp(xpTotal). Every write ends by reading that sum back - the total is never carried forward
 * from a value read earlier, so nothing can drift. Callers hold PlayerProgressionLock for the player (taken before
 * they read anything), which makes the read-back the committed truth plus this transaction's own writes.
 *
 * Entries are written with plain SQL in multi-row statements, a documented bulk operation (CLAUDE.md): a full rebuild
 * writes thousands of rows, and the writes must be visible to the reads of the same rebuild (the ORM would hold them
 * back until the flush). Flush of the Player change is owned by the messenger doctrine_transaction middleware.
 */
readonly final class XpLedger
{
    private const int INSERT_CHUNK = 500;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<XpEntryDraft> $drafts
     */
    public function append(Player $player, array $drafts): XpLevelChange
    {
        return $this->rewrite($player, [], $drafts);
    }

    /**
     * Removes the given entries of the player and appends the drafts, then re-reads the total.
     *
     * @param list<string> $entryIdsToRemove
     * @param list<XpEntryDraft> $drafts
     */
    public function rewrite(Player $player, array $entryIdsToRemove, array $drafts): XpLevelChange
    {
        $playerId = $player->id->toString();

        if ($entryIdsToRemove !== []) {
            $this->database->executeStatement(
                'DELETE FROM xp_entry WHERE player_id = :playerId AND id IN (:ids)',
                ['playerId' => $playerId, 'ids' => $entryIdsToRemove],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        $this->insert($playerId, $drafts);

        return $this->resync($player);
    }

    /**
     * Removes every entry of the player - a full rebuild starts from an empty ledger.
     */
    public function wipe(Player $player): void
    {
        $this->database->executeStatement(
            'DELETE FROM xp_entry WHERE player_id = :playerId',
            ['playerId' => $player->id->toString()],
        );
    }

    /**
     * Sets Player.xpTotal / level to the ledger's sum.
     */
    public function resync(Player $player): XpLevelChange
    {
        $sum = $this->database->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM xp_entry WHERE player_id = :playerId',
            ['playerId' => $player->id->toString()],
        );
        $newXpTotal = is_numeric($sum) ? (int) $sum : 0;
        $newLevel = LevelTable::levelForXp($newXpTotal);

        $previousXpTotal = $player->xpTotal;
        $previousLevel = $player->level;

        // Written here too, not only through the entity: an entity loaded before the lock may hold an older total
        // than the database, and the unit of work only writes what differs from what it loaded
        $this->database->executeStatement(
            'UPDATE player SET xp_total = :xpTotal, level = :level WHERE id = :playerId AND (xp_total != :xpTotal OR level != :level)',
            ['xpTotal' => $newXpTotal, 'level' => $newLevel, 'playerId' => $player->id->toString()],
        );
        $player->updateExperience($newXpTotal, $newLevel);

        return new XpLevelChange(
            previousXpTotal: $previousXpTotal,
            newXpTotal: $newXpTotal,
            previousLevel: $previousLevel,
            newLevel: $newLevel,
        );
    }

    /**
     * @param list<XpEntryDraft> $drafts
     */
    private function insert(string $playerId, array $drafts): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        foreach (array_chunk($drafts, self::INSERT_CHUNK) as $chunk) {
            $values = [];
            $parameters = [];
            $types = [];

            foreach ($chunk as $index => $draft) {
                $values[] = sprintf(
                    '(:id%1$d, :playerId, :amount%1$d, :reason%1$d, :inWeeklyDelta%1$d, :earnedAt%1$d, :createdAt, :solvingTimeId%1$d)',
                    $index,
                );
                $parameters['id' . $index] = Uuid::uuid7()->toString();
                $parameters['amount' . $index] = $draft->amount;
                $parameters['reason' . $index] = $draft->reason->value;
                $parameters['inWeeklyDelta' . $index] = $draft->inWeeklyDelta;
                $types['inWeeklyDelta' . $index] = ParameterType::BOOLEAN;
                $parameters['earnedAt' . $index] = $draft->earnedAt->format('Y-m-d H:i:s');
                $parameters['solvingTimeId' . $index] = $draft->solvingTimeId?->toString();
            }

            $parameters['playerId'] = $playerId;
            $parameters['createdAt'] = $now;

            $this->database->executeStatement(
                'INSERT INTO xp_entry (id, player_id, amount, reason, in_weekly_delta, earned_at, created_at, solving_time_id) VALUES '
                . implode(', ', $values),
                $parameters,
                $types,
            );
        }
    }
}
