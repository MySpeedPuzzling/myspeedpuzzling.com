<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250903214307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_borrowing ALTER puzzle_id SET NOT NULL');
        $this->addSql('ALTER TABLE puzzle_borrowing ALTER owner_id SET NOT NULL');
        $this->addSql('DROP INDEX unique_player_collection_system_type');
        $this->addSql('ALTER TABLE puzzle_collection ALTER player_id SET NOT NULL');
        $this->addSql('DROP INDEX unique_player_puzzle_custom_collection');
        $this->addSql('ALTER TABLE puzzle_collection_item ALTER puzzle_id SET NOT NULL');
        $this->addSql('ALTER TABLE puzzle_collection_item ALTER player_id SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_collection ALTER player_id DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX unique_player_collection_system_type ON puzzle_collection (player_id, system_type) WHERE (system_type IS NOT NULL)');
        $this->addSql('ALTER TABLE puzzle_collection_item ALTER puzzle_id DROP NOT NULL');
        $this->addSql('ALTER TABLE puzzle_collection_item ALTER player_id DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX unique_player_puzzle_custom_collection ON puzzle_collection_item (player_id, puzzle_id) WHERE (collection_id IS NOT NULL)');
        $this->addSql('ALTER TABLE puzzle_borrowing ALTER puzzle_id DROP NOT NULL');
        $this->addSql('ALTER TABLE puzzle_borrowing ALTER owner_id DROP NOT NULL');
    }
}
