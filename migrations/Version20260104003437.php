<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260104003437 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change PuzzleMergeRequest foreign keys from CASCADE to SET NULL for audit trail';
    }

    public function up(Schema $schema): void
    {
        // Add the new column for storing puzzle name
        $this->addSql('ALTER TABLE puzzle_merge_request ADD source_puzzle_name VARCHAR(255) DEFAULT NULL');

        // Populate source_puzzle_name from current puzzle data before changing constraints
        $this->addSql('UPDATE puzzle_merge_request SET source_puzzle_name = (SELECT p.name FROM puzzle p WHERE p.id = puzzle_merge_request.source_puzzle_id)');

        // Drop old CASCADE constraints
        $this->addSql('ALTER TABLE puzzle_merge_request DROP CONSTRAINT fk_c53156fbb11ffbdc');
        $this->addSql('ALTER TABLE puzzle_merge_request DROP CONSTRAINT fk_c53156fbe1cfe6f5');

        // Make columns nullable
        $this->addSql('ALTER TABLE puzzle_merge_request ALTER source_puzzle_id DROP NOT NULL');
        $this->addSql('ALTER TABLE puzzle_merge_request ALTER reporter_id DROP NOT NULL');

        // Add new SET NULL constraints
        $this->addSql('ALTER TABLE puzzle_merge_request ADD CONSTRAINT FK_C53156FBB11FFBDC FOREIGN KEY (source_puzzle_id) REFERENCES puzzle (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_merge_request ADD CONSTRAINT FK_C53156FBE1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // This down migration is not safe if there are NULL values in the columns
        $this->addSql('ALTER TABLE puzzle_merge_request DROP CONSTRAINT FK_C53156FBB11FFBDC');
        $this->addSql('ALTER TABLE puzzle_merge_request DROP CONSTRAINT FK_C53156FBE1CFE6F5');
        $this->addSql('ALTER TABLE puzzle_merge_request DROP source_puzzle_name');
        $this->addSql('ALTER TABLE puzzle_merge_request ALTER source_puzzle_id SET NOT NULL');
        $this->addSql('ALTER TABLE puzzle_merge_request ALTER reporter_id SET NOT NULL');
        $this->addSql('ALTER TABLE puzzle_merge_request ADD CONSTRAINT fk_c53156fbb11ffbdc FOREIGN KEY (source_puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_merge_request ADD CONSTRAINT fk_c53156fbe1cfe6f5 FOREIGN KEY (reporter_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
