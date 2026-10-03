<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003115108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE manufacturer_slug_redirect (slug VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, manufacturer_id UUID NOT NULL, PRIMARY KEY (slug))');
        $this->addSql('CREATE INDEX IDX_B4F1628EA23B42D ON manufacturer_slug_redirect (manufacturer_id)');
        $this->addSql('ALTER TABLE manufacturer_slug_redirect ADD CONSTRAINT FK_B4F1628EA23B42D FOREIGN KEY (manufacturer_id) REFERENCES manufacturer (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE manufacturer_slug_redirect DROP CONSTRAINT FK_B4F1628EA23B42D');
        $this->addSql('DROP TABLE manufacturer_slug_redirect');
    }
}
