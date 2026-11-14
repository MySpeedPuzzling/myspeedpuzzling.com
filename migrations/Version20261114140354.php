<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261114140354 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE player_custom_field (choices JSONB NOT NULL, archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, name VARCHAR(60) NOT NULL, standard_key VARCHAR(40) DEFAULT NULL, type VARCHAR(16) NOT NULL, for_results BOOLEAN NOT NULL, for_collection_items BOOLEAN NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_18C5ED1399E6F5DF ON player_custom_field (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_18C5ED1399E6F5DF5E237E06 ON player_custom_field (player_id, name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_18C5ED1399E6F5DF79F9787A ON player_custom_field (player_id, standard_key)');
        $this->addSql('CREATE TABLE solving_time_custom_values (custom_values JSONB NOT NULL, id UUID NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, solving_time_id UUID NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B321A7B5D6B05C60 ON solving_time_custom_values (solving_time_id)');
        $this->addSql('CREATE INDEX IDX_B321A7B599E6F5DF ON solving_time_custom_values (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B321A7B5D6B05C6099E6F5DF ON solving_time_custom_values (solving_time_id, player_id)');
        $this->addSql('ALTER TABLE player_custom_field ADD CONSTRAINT FK_18C5ED1399E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE solving_time_custom_values ADD CONSTRAINT FK_B321A7B5D6B05C60 FOREIGN KEY (solving_time_id) REFERENCES puzzle_solving_time (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE solving_time_custom_values ADD CONSTRAINT FK_B321A7B599E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player_custom_field DROP CONSTRAINT FK_18C5ED1399E6F5DF');
        $this->addSql('ALTER TABLE solving_time_custom_values DROP CONSTRAINT FK_B321A7B5D6B05C60');
        $this->addSql('ALTER TABLE solving_time_custom_values DROP CONSTRAINT FK_B321A7B599E6F5DF');
        $this->addSql('DROP TABLE player_custom_field');
        $this->addSql('DROP TABLE solving_time_custom_values');
    }
}
