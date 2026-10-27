<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027171819 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Candidate prediction model v7: the difficulty-adjusted pace offsets (candidate_pace_offset)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE candidate_pace_offset (player_id UUID NOT NULL, pieces_count INT NOT NULL, valid_from TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, valid_from_tracked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, pace_offset DOUBLE PRECISION NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (player_id, pieces_count, valid_from, valid_from_tracked_at))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE candidate_pace_offset');
    }
}
