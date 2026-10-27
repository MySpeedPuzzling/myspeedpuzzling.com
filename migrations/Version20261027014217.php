<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * difficulty_prior 1.2.0 (docs/features/internal-api.md "Puzzle difficulty labels and estimates"): optional values stored
 * with each picture estimate. Nullable, no default - a metadata-only ALTER on Postgres.
 */
final class Version20261027014217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Picture estimates 1.2.0: the 50 % interval, pixels per piece, crop trust and 16 picture factors per estimate (all nullable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate ADD log_p25 DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate ADD log_p75 DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate ADD pixels_per_piece DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate ADD crop_trust DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate ADD picture_factors JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate ADD picture_factors_version VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate DROP log_p25');
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate DROP log_p75');
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate DROP pixels_per_piece');
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate DROP crop_trust');
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate DROP picture_factors');
        $this->addSql('ALTER TABLE puzzle_difficulty_estimate DROP picture_factors_version');
    }
}
