<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002005638 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE duplicate_puzzle_signal (status VARCHAR(255) NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, merge_request_id UUID DEFAULT NULL, id UUID NOT NULL, detected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, matching_results INT NOT NULL, matching_people INT NOT NULL, example_player_id UUID NOT NULL, example_seconds INT NOT NULL, example_day DATE NOT NULL, puzzle_a_id UUID NOT NULL, puzzle_b_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8FC73AEB692E05F6 ON duplicate_puzzle_signal (puzzle_a_id)');
        $this->addSql('CREATE INDEX IDX_8FC73AEB7B9BAA18 ON duplicate_puzzle_signal (puzzle_b_id)');
        $this->addSql('CREATE INDEX IDX_8FC73AEB7B00651C ON duplicate_puzzle_signal (status)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8FC73AEB692E05F67B9BAA18 ON duplicate_puzzle_signal (puzzle_a_id, puzzle_b_id)');
        $this->addSql('ALTER TABLE duplicate_puzzle_signal ADD CONSTRAINT FK_8FC73AEB692E05F6 FOREIGN KEY (puzzle_a_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE duplicate_puzzle_signal ADD CONSTRAINT FK_8FC73AEB7B9BAA18 FOREIGN KEY (puzzle_b_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE duplicate_puzzle_signal DROP CONSTRAINT FK_8FC73AEB692E05F6');
        $this->addSql('ALTER TABLE duplicate_puzzle_signal DROP CONSTRAINT FK_8FC73AEB7B9BAA18');
        $this->addSql('DROP TABLE duplicate_puzzle_signal');
    }
}
