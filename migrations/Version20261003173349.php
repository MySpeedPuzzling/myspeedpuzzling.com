<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003173349 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partial index on finished_at for solo results - the Hub\'s "Most active solo players" (docs/database-indexes.md)';
    }

    public function up(Schema $schema): void
    {
        // Partial on purpose: a plain (finished_at) index made MIN(finished_at) of one player (getOldestResultDate())
        // walk the date index instead of the player's rows - 0.6 -> 230 ms for a heavy player
        $this->addSql("CREATE INDEX custom_pst_finished_at_solo ON puzzle_solving_time (finished_at) WHERE puzzling_type = 'solo'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX custom_pst_finished_at_solo');
    }
}
