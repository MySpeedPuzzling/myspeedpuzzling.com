<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002021540 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX IDX_F350A7EC96635A75 ON result_duplicate_case (time_a_id)');
        $this->addSql('CREATE INDEX IDX_F350A7EC84D6F59B ON result_duplicate_case (time_b_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_F350A7EC96635A75');
        $this->addSql('DROP INDEX IDX_F350A7EC84D6F59B');
    }
}
