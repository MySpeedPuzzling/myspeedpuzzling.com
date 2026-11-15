<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115034617 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_import_row ADD solving_time_id UUID DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DF686C82D6B05C60 ON data_import_row (solving_time_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_DF686C82D6B05C60');
        $this->addSql('ALTER TABLE data_import_row DROP solving_time_id');
    }
}
