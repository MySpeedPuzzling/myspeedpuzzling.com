<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Candidate prediction model (v4, never shown): candidate_puzzle_evidence + candidate_puzzle_prior';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE candidate_puzzle_evidence (puzzle_id UUID NOT NULL, index_count INT NOT NULL, weight_sum DOUBLE PRECISION NOT NULL, weighted_log_sum DOUBLE PRECISION NOT NULL, log_sum DOUBLE PRECISION NOT NULL, log_square_sum DOUBLE PRECISION NOT NULL, noise_sum DOUBLE PRECISION NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (puzzle_id))');
        $this->addSql('CREATE TABLE candidate_puzzle_prior (puzzle_id UUID NOT NULL, brand_prior_log DOUBLE PRECISION NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (puzzle_id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE candidate_puzzle_evidence');
        $this->addSql('DROP TABLE candidate_puzzle_prior');
    }
}
