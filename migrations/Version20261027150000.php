<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Graveyard + tombstones of deleted solving times (docs/features/api/solving-time-sync.md)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE solving_time_graveyard (id UUID NOT NULL, solving_time_id UUID NOT NULL, player_id UUID NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_via VARCHAR(30) NOT NULL, snapshot JSONB NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A5E4F7FC99E6F5DF ON solving_time_graveyard (player_id)');
        $this->addSql('CREATE INDEX IDX_A5E4F7FC4AF38FD1 ON solving_time_graveyard (deleted_at)');
        $this->addSql('CREATE TABLE solving_time_tombstone (id UUID NOT NULL, solving_time_id UUID NOT NULL, player_id UUID NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_ED47D7599E6F5DF4AF38FD1D6B05C60 ON solving_time_tombstone (player_id, deleted_at, solving_time_id)');
        $this->addSql('CREATE INDEX IDX_ED47D75D6B05C60 ON solving_time_tombstone (solving_time_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE solving_time_graveyard');
        $this->addSql('DROP TABLE solving_time_tombstone');
    }
}
