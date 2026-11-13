<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112194053 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A solving time without a tracker: an imported event result nobody has claimed yet, also in its grave (docs/features/event-results-import)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_solving_time ALTER player_id DROP NOT NULL');
        $this->addSql('ALTER TABLE solving_time_graveyard ALTER player_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_solving_time ALTER player_id SET NOT NULL');
        $this->addSql('ALTER TABLE solving_time_graveyard ALTER player_id SET NOT NULL');
    }
}
