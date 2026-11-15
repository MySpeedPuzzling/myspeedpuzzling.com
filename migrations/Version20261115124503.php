<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115124503 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_import_daily_count (notification_id UUID DEFAULT NULL, id UUID NOT NULL, day DATE NOT NULL, results INT NOT NULL, importer_id UUID NOT NULL, partner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AB3790697FCFE58E ON data_import_daily_count (importer_id)');
        $this->addSql('CREATE INDEX IDX_AB3790699393F8FE ON data_import_daily_count (partner_id)');
        $this->addSql('CREATE INDEX IDX_AB3790699393F8FEE5A02990 ON data_import_daily_count (partner_id, day)');
        $this->addSql('CREATE INDEX IDX_AB379069EF1A9D84 ON data_import_daily_count (notification_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AB3790697FCFE58E9393F8FEE5A02990 ON data_import_daily_count (importer_id, partner_id, day)');
        $this->addSql('CREATE TABLE import_partner_opt_out (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, importer_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D717298499E6F5DF ON import_partner_opt_out (player_id)');
        $this->addSql('CREATE INDEX IDX_D71729847FCFE58E ON import_partner_opt_out (importer_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D717298499E6F5DF7FCFE58E ON import_partner_opt_out (player_id, importer_id)');
        $this->addSql('ALTER TABLE data_import_daily_count ADD CONSTRAINT FK_AB3790697FCFE58E FOREIGN KEY (importer_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_import_daily_count ADD CONSTRAINT FK_AB3790699393F8FE FOREIGN KEY (partner_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_partner_opt_out ADD CONSTRAINT FK_D717298499E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_partner_opt_out ADD CONSTRAINT FK_D71729847FCFE58E FOREIGN KEY (importer_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import_daily_count DROP CONSTRAINT FK_AB3790697FCFE58E');
        $this->addSql('ALTER TABLE data_import_daily_count DROP CONSTRAINT FK_AB3790699393F8FE');
        $this->addSql('ALTER TABLE import_partner_opt_out DROP CONSTRAINT FK_D717298499E6F5DF');
        $this->addSql('ALTER TABLE import_partner_opt_out DROP CONSTRAINT FK_D71729847FCFE58E');
        $this->addSql('DROP TABLE data_import_daily_count');
        $this->addSql('DROP TABLE import_partner_opt_out');
    }
}
