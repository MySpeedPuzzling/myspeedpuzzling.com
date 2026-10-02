<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002011500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE result_review_contact (status VARCHAR(255) NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, skipped_reason VARCHAR(255) DEFAULT NULL, page_visited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reacted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, type VARCHAR(255) NOT NULL, priority SMALLINT NOT NULL, last_active_on DATE DEFAULT NULL, case_ids JSON NOT NULL, removal_ids JSON NOT NULL, planned_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4E05806799E6F5DF ON result_review_contact (player_id)');
        $this->addSql('CREATE INDEX IDX_4E05806799E6F5DF7B00651C ON result_review_contact (player_id, status)');
        $this->addSql('CREATE INDEX IDX_4E0580677B00651C96E4F388 ON result_review_contact (status, sent_at)');
        $this->addSql('ALTER TABLE result_review_contact ADD CONSTRAINT FK_4E05806799E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player ADD result_emails_enabled BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE result_review_contact DROP CONSTRAINT FK_4E05806799E6F5DF');
        $this->addSql('DROP TABLE result_review_contact');
        $this->addSql('ALTER TABLE player DROP result_emails_enabled');
    }
}
