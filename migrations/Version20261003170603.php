<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003170603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sell_swap_list_item_event (added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, sell_swap_list_item_id UUID NOT NULL, competition_id UUID NOT NULL, PRIMARY KEY (sell_swap_list_item_id, competition_id))');
        // No index of its own on sell_swap_list_item_id: the primary key leads with it (the schema stays in sync without one)
        $this->addSql('CREATE INDEX IDX_8804D3557B39D312 ON sell_swap_list_item_event (competition_id)');
        $this->addSql('ALTER TABLE sell_swap_list_item_event ADD CONSTRAINT FK_8804D3551E4F9FF9 FOREIGN KEY (sell_swap_list_item_id) REFERENCES sell_swap_list_item (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE sell_swap_list_item_event ADD CONSTRAINT FK_8804D3557B39D312 FOREIGN KEY (competition_id) REFERENCES competition (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sell_swap_list_item_event DROP CONSTRAINT FK_8804D3551E4F9FF9');
        $this->addSql('ALTER TABLE sell_swap_list_item_event DROP CONSTRAINT FK_8804D3557B39D312');
        $this->addSql('DROP TABLE sell_swap_list_item_event');
    }
}
