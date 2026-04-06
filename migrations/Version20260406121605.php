<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260406121605 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_b50a2cb1989d9b62');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B50A2CB15278319C989D9B62 ON competition (series_id, slug)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_B50A2CB15278319C989D9B62');
        $this->addSql('CREATE UNIQUE INDEX uniq_b50a2cb1989d9b62 ON competition (slug)');
    }
}
