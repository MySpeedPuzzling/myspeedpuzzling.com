<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261001234812 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE result_duplicate_case (status VARCHAR(255) NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, resolved_via VARCHAR(255) DEFAULT NULL, id UUID NOT NULL, time_a_id UUID NOT NULL, time_b_id UUID NOT NULL, tier VARCHAR(255) NOT NULL, kind VARCHAR(255) NOT NULL, detected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, detected_by VARCHAR(255) NOT NULL, snapshot JSON NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F350A7EC99E6F5DF ON result_duplicate_case (player_id)');
        $this->addSql('CREATE INDEX IDX_F350A7EC99E6F5DF7B00651C ON result_duplicate_case (player_id, status)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F350A7EC99E6F5DF96635A7584D6F59B ON result_duplicate_case (player_id, time_a_id, time_b_id)');
        $this->addSql('ALTER TABLE result_duplicate_case ADD CONSTRAINT FK_F350A7EC99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE result_duplicate_case DROP CONSTRAINT FK_F350A7EC99E6F5DF');
        $this->addSql('DROP TABLE result_duplicate_case');
    }
}
