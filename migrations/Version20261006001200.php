<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261006001200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_round ADD timezone VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_round_puzzle ADD reveal_mode VARCHAR(255) DEFAULT \'automatic\' NOT NULL');
        $this->addSql('ALTER TABLE competition_round_puzzle ADD reveal_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_round_puzzle ADD hides_everywhere BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_round DROP timezone');
        $this->addSql('ALTER TABLE competition_round_puzzle DROP reveal_mode');
        $this->addSql('ALTER TABLE competition_round_puzzle DROP reveal_at');
        $this->addSql('ALTER TABLE competition_round_puzzle DROP hides_everywhere');
    }
}
