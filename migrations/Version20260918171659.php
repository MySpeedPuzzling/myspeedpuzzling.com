<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GIN index for "who follows these players" (player.favorite_players).
 *
 * GetSubscribedPlayers runs for every added solving time and used to unnest
 * every player's favorites list (json_array_elements_text + DISTINCT) - 12.6 ms
 * on average on production, whatever the number of followers. It now asks
 * `favorite_players::jsonb ?| ARRAY[...]`, which this index serves (default
 * jsonb_ops; jsonb_path_ops cannot answer ?|). Measured on production without
 * the index: most-followed player (666 followers) 12.1 -> 4.7 ms, a player with
 * 2 followers 11.7 -> 4.7 ms, five players at once 14.4 -> 5.4 ms - the rest is
 * the seq scan this index removes. On a local copy with production's shape:
 * 0.02-0.07 ms for a typical player, 1.4 ms for the most followed.
 *
 * The same index also serves the `favorite_players::jsonb @> jsonb_build_array(...)`
 * lookups (GetPlayerConnections followers list and count, the favorites scrub in
 * DeletePlayerHandler), which scanned the whole table as well.
 *
 * Custom (Doctrine cannot manage expression GIN indexes): the custom_ prefix keeps
 * CustomIndexFilteringSchemaManagerFactory from ever generating a DROP for it,
 * and tests/bootstrap.php mirrors it. Registry: docs/database-indexes.md.
 *
 * Plain CREATE INDEX inside the migration transaction: the player table is
 * ~11k rows / 6 MB with ~20k favorites in total, so the build takes well under a
 * second (about 1 MB of index).
 */
final class Version20260918171659 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GIN index on player favorites for the followers lookup';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS custom_player_favorite_players_gin ON player USING GIN ((favorite_players::jsonb))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_player_favorite_players_gin');
    }
}
