<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241230165038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE membership DROP card_last4_digits');
        $this->addSql('ALTER TABLE membership DROP stripe_plan_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE membership ADD card_last4_digits VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE membership ADD stripe_plan_id VARCHAR(255) DEFAULT NULL');
    }
}
