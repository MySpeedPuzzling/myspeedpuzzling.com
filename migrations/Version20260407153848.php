<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260407153848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shortcut and tag to competition_series';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_series ADD shortcut VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_series ADD tag_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_series ADD CONSTRAINT FK_5553C1FEBAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5553C1FE2EF83F9C ON competition_series (shortcut)');
        $this->addSql('CREATE INDEX IDX_5553C1FEBAD26311 ON competition_series (tag_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_series DROP CONSTRAINT FK_5553C1FEBAD26311');
        $this->addSql('DROP INDEX UNIQ_5553C1FE2EF83F9C');
        $this->addSql('DROP INDEX IDX_5553C1FEBAD26311');
        $this->addSql('ALTER TABLE competition_series DROP shortcut');
        $this->addSql('ALTER TABLE competition_series DROP tag_id');
    }
}
