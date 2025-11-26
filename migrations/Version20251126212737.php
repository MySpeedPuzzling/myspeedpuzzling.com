<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251126212737 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate data from tmp_collection to collection_item (system collection)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            INSERT INTO collection_item (id, collection_id, player_id, puzzle_id, comment, added_at)
            SELECT
                gen_random_uuid(),
                NULL,
                player_id,
                puzzle_id,
                NULL,
                NOW()
            FROM tmp_collection
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('
            DELETE FROM collection_item
            WHERE collection_id IS NULL
            AND (player_id, puzzle_id) IN (
                SELECT player_id, puzzle_id FROM tmp_collection
            )
        ');
    }
}
