<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The players off the MSP Rating ladder (private or opted out of rankings) as a tiny partial index on player
 * (docs/database-indexes.md): every "#rank of total" on a profile counts the ladder's players "NOT IN" this set
 * (GetPlayerRatingRanking::sqlOnLadderByPlayerId()) instead of joining each counted row to its player, which read the
 * whole player table on every count. GetRanking's private-players list reads it too (is_private implies the predicate).
 */
final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partial index on player for the players off the MSP Rating ladder (rank counts)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_player_off_ladder ON player (id) INCLUDE (is_private) WHERE is_private OR ranking_opted_out');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_player_off_ladder');
    }
}
