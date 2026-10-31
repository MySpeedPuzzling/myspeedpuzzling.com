<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031183839 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'candidate_picture_projection: the candidate model taste projections of the pictures (the shown v7 Puzzle Picker SQL)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE candidate_picture_projection (puzzle_id UUID NOT NULL, image_fingerprint VARCHAR(64) NOT NULL, estimate_received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, factors_version VARCHAR(50) NOT NULL, taste_fitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, factor_sum1 DOUBLE PRECISION NOT NULL, factor_sum2 DOUBLE PRECISION NOT NULL, factor_sum3 DOUBLE PRECISION NOT NULL, factor_sum4 DOUBLE PRECISION NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (puzzle_id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE candidate_picture_projection');
    }
}
