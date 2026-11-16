<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115225755 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_merge_request ADD reverted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_merge_request ADD reverted_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_merge_request ADD CONSTRAINT FK_C53156FBA8E1562 FOREIGN KEY (reverted_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_C53156FBA8E1562 ON puzzle_merge_request (reverted_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_merge_request DROP CONSTRAINT FK_C53156FBA8E1562');
        $this->addSql('DROP INDEX IDX_C53156FBA8E1562');
        $this->addSql('ALTER TABLE puzzle_merge_request DROP reverted_at');
        $this->addSql('ALTER TABLE puzzle_merge_request DROP reverted_by_id');
    }
}
