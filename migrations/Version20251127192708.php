<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251127192708 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support non-registered users in lending/borrowing: add name columns and make player IDs nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lent_puzzle ADD owner_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE lent_puzzle ADD current_holder_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE lent_puzzle ALTER owner_player_id DROP NOT NULL');
        $this->addSql('ALTER TABLE lent_puzzle ALTER current_holder_player_id DROP NOT NULL');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ADD from_player_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ADD to_player_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ALTER to_player_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lent_puzzle DROP owner_name');
        $this->addSql('ALTER TABLE lent_puzzle DROP current_holder_name');
        $this->addSql('ALTER TABLE lent_puzzle ALTER owner_player_id SET NOT NULL');
        $this->addSql('ALTER TABLE lent_puzzle ALTER current_holder_player_id SET NOT NULL');
        $this->addSql('ALTER TABLE lent_puzzle_transfer DROP from_player_name');
        $this->addSql('ALTER TABLE lent_puzzle_transfer DROP to_player_name');
        $this->addSql('ALTER TABLE lent_puzzle_transfer ALTER to_player_id SET NOT NULL');
    }
}
