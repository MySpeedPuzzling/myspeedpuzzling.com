<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018230621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insights: monthly player_insights_snapshot (strengths, weak spots, form, rating as a small JSON)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE player_insights_snapshot (id UUID NOT NULL, month DATE NOT NULL, data JSONB NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7A36995199E6F5DF ON player_insights_snapshot (player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7A36995199E6F5DF8EB61006 ON player_insights_snapshot (player_id, month)');
        $this->addSql('ALTER TABLE player_insights_snapshot ADD CONSTRAINT FK_7A36995199E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player_insights_snapshot DROP CONSTRAINT FK_7A36995199E6F5DF');
        $this->addSql('DROP TABLE player_insights_snapshot');
    }
}
