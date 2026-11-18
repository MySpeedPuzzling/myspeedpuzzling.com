<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Competition toolkit v2 S5: `competition_standing.numbered_across_stages` - a `stages` standing numbers everybody
 * across the bracket's stages. Additive, default false (every existing standing unchanged). Hand-written (no-Docker
 * mode), matching the entity mapping as doctrine:migrations:diff would.
 */
final class Version20261118150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Competition standing: numbered_across_stages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_standing ADD numbered_across_stages BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_standing DROP numbered_across_stages');
    }
}
