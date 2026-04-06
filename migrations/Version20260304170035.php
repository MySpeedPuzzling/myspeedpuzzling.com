<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260304170035 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE round_table (id UUID NOT NULL, position INT NOT NULL, label VARCHAR(255) NOT NULL, row_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_205A9BBF83A269F2 ON round_table (row_id)');
        $this->addSql('CREATE TABLE table_row (id UUID NOT NULL, position INT NOT NULL, label VARCHAR(255) DEFAULT NULL, round_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8DD57CBFA6005CA0 ON table_row (round_id)');
        $this->addSql('CREATE TABLE table_spot (id UUID NOT NULL, position INT NOT NULL, player_name VARCHAR(255) DEFAULT NULL, table_id UUID NOT NULL, player_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F3E43AB8ECFF285C ON table_spot (table_id)');
        $this->addSql('CREATE INDEX IDX_F3E43AB899E6F5DF ON table_spot (player_id)');
        $this->addSql('ALTER TABLE round_table ADD CONSTRAINT FK_205A9BBF83A269F2 FOREIGN KEY (row_id) REFERENCES table_row (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE table_row ADD CONSTRAINT FK_8DD57CBFA6005CA0 FOREIGN KEY (round_id) REFERENCES competition_round (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE table_spot ADD CONSTRAINT FK_F3E43AB8ECFF285C FOREIGN KEY (table_id) REFERENCES round_table (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE table_spot ADD CONSTRAINT FK_F3E43AB899E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE round_table DROP CONSTRAINT FK_205A9BBF83A269F2');
        $this->addSql('ALTER TABLE table_row DROP CONSTRAINT FK_8DD57CBFA6005CA0');
        $this->addSql('ALTER TABLE table_spot DROP CONSTRAINT FK_F3E43AB8ECFF285C');
        $this->addSql('ALTER TABLE table_spot DROP CONSTRAINT FK_F3E43AB899E6F5DF');
        $this->addSql('DROP TABLE round_table');
        $this->addSql('DROP TABLE table_row');
        $this->addSql('DROP TABLE table_spot');
    }
}
