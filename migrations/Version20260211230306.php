<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260211230306 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sell_swap_list_item ADD reserved BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE sell_swap_list_item ADD reserved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE sell_swap_list_item ADD reserved_for_player_id UUID DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN sell_swap_list_item.reserved_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sell_swap_list_item DROP reserved');
        $this->addSql('ALTER TABLE sell_swap_list_item DROP reserved_at');
        $this->addSql('ALTER TABLE sell_swap_list_item DROP reserved_for_player_id');
    }
}
