<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Real events L2a (shared-puzzles-and-divisions.md D7): `competition_standing.division_id` - a standing limited to one
 * division. Additive, nullable (every existing standing = everybody). Hand-written under no-Docker mode, matching the
 * entity mapping as doctrine:migrations:diff generates it (FK + index names from Doctrine's identifier hash of
 * competition_standing + division_id, ON DELETE SET NULL).
 */
final class Version20261118170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Competition standing: division_id (standings per division)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_standing ADD division_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_standing ADD CONSTRAINT FK_50EBB1F741859289 FOREIGN KEY (division_id) REFERENCES competition_division (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_50EBB1F741859289 ON competition_standing (division_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_standing DROP CONSTRAINT FK_50EBB1F741859289');
        $this->addSql('DROP INDEX IDX_50EBB1F741859289');
        $this->addSql('ALTER TABLE competition_standing DROP division_id');
    }
}
