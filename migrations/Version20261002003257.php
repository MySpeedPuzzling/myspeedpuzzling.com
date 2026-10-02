<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002003257 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE result_auto_removal (undone_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, removed_time_id UUID NOT NULL, kept_time_id UUID NOT NULL, case_id UUID NOT NULL, snapshot JSON NOT NULL, removed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_18EB32E499E6F5DF ON result_auto_removal (player_id)');
        $this->addSql('CREATE INDEX IDX_18EB32E499E6F5DF455180A5 ON result_auto_removal (player_id, removed_at)');
        $this->addSql('CREATE INDEX IDX_18EB32E4D38A4C4 ON result_auto_removal (removed_time_id)');
        $this->addSql('ALTER TABLE result_auto_removal ADD CONSTRAINT FK_18EB32E499E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE result_auto_removal DROP CONSTRAINT FK_18EB32E499E6F5DF');
        $this->addSql('DROP TABLE result_auto_removal');
    }
}
