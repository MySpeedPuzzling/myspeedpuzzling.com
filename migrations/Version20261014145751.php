<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261014145751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE online_review_moment_verdict (id UUID NOT NULL, verdict VARCHAR(16) NOT NULL, marked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, online_entry_id UUID NOT NULL, event_id UUID NOT NULL, marked_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5E3C25FE002008A ON online_review_moment_verdict (online_entry_id)');
        $this->addSql('CREATE INDEX IDX_5E3C25F61703BF ON online_review_moment_verdict (marked_by_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E3C25F71F7E88B ON online_review_moment_verdict (event_id)');
        $this->addSql('ALTER TABLE online_review_moment_verdict ADD CONSTRAINT FK_5E3C25FE002008A FOREIGN KEY (online_entry_id) REFERENCES online_entry (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE online_review_moment_verdict ADD CONSTRAINT FK_5E3C25F71F7E88B FOREIGN KEY (event_id) REFERENCES online_entry_event (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE online_review_moment_verdict ADD CONSTRAINT FK_5E3C25F61703BF FOREIGN KEY (marked_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE online_review_moment_verdict DROP CONSTRAINT FK_5E3C25FE002008A');
        $this->addSql('ALTER TABLE online_review_moment_verdict DROP CONSTRAINT FK_5E3C25F71F7E88B');
        $this->addSql('ALTER TABLE online_review_moment_verdict DROP CONSTRAINT FK_5E3C25F61703BF');
        $this->addSql('DROP TABLE online_review_moment_verdict');
    }
}
