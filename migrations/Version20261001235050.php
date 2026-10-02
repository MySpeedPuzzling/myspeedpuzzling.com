<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261001235050 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE result_duplicate_prevention (id UUID NOT NULL, kind VARCHAR(255) NOT NULL, time_id UUID NOT NULL, puzzle_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, via VARCHAR(255) NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4EF5AAC199E6F5DF ON result_duplicate_prevention (player_id)');
        $this->addSql('CREATE INDEX IDX_4EF5AAC18B8E8428 ON result_duplicate_prevention (created_at)');
        $this->addSql('ALTER TABLE result_duplicate_prevention ADD CONSTRAINT FK_4EF5AAC199E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD created_via VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE result_duplicate_prevention DROP CONSTRAINT FK_4EF5AAC199E6F5DF');
        $this->addSql('DROP TABLE result_duplicate_prevention');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP created_via');
    }
}
