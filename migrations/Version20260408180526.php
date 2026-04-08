<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408180526 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename tribute table to referral and tribute_id column to referral_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tribute RENAME TO referral');
        $this->addSql('ALTER TABLE affiliate_payout RENAME COLUMN tribute_id TO referral_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE referral RENAME TO tribute');
        $this->addSql('ALTER TABLE affiliate_payout RENAME COLUMN referral_id TO tribute_id');
    }
}
