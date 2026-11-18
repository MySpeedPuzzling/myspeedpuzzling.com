<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Participant linking L4a: competition_participant.claim_token (personal claim links)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition_participant ADD claim_token VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FF3EC46F637A4A7D ON competition_participant (claim_token)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_FF3EC46F637A4A7D');
        $this->addSql('ALTER TABLE competition_participant DROP claim_token');
    }
}
