<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260102211928 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX IDX_22A6DFDFDD8EF047 ON puzzle (pieces_count)');
        $this->addSql('CREATE INDEX IDX_22A6DFDF347639A5 ON puzzle (identification_number)');
        $this->addSql('CREATE INDEX IDX_22A6DFDF67B1C660 ON puzzle (ean)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_22A6DFDFDD8EF047');
        $this->addSql('DROP INDEX IDX_22A6DFDF347639A5');
        $this->addSql('DROP INDEX IDX_22A6DFDF67B1C660');
    }
}
