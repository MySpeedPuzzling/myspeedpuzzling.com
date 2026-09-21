<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260921134417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'History of player code changes (player_code_change) - a log, nothing reads it yet';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE player_code_change (id UUID NOT NULL, previous_code VARCHAR(255) NOT NULL, new_code VARCHAR(255) NOT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_67E64F1799E6F5DF ON player_code_change (player_id)');
        $this->addSql('CREATE INDEX IDX_67E64F1799E6F5DF452042E1 ON player_code_change (player_id, changed_at)');
        $this->addSql('CREATE INDEX IDX_67E64F1781DA585D ON player_code_change (previous_code)');
        $this->addSql('ALTER TABLE player_code_change ADD CONSTRAINT FK_67E64F1799E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player_code_change DROP CONSTRAINT FK_67E64F1799E6F5DF');
        $this->addSql('DROP TABLE player_code_change');
    }
}
