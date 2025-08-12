<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250812221620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename player_puzzle_collection table to player_puzzle_collection_old for collections rework';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_puzzle_collection RENAME TO player_puzzle_collection_old');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_puzzle_collection_old RENAME TO player_puzzle_collection');
    }
}
