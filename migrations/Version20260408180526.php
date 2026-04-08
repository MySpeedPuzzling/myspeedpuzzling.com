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
        $this->addSql('ALTER INDEX idx_e21d2ea69f12c49a RENAME TO IDX_73079D009F12C49A');
        $this->addSql('ALTER INDEX uniq_e21d2ea67808b1ad RENAME TO UNIQ_73079D007808B1AD');
        $this->addSql('ALTER TABLE affiliate_payout RENAME COLUMN tribute_id TO referral_id');
        $this->addSql('ALTER INDEX idx_c1fe99f8d3b581f5 RENAME TO IDX_C1FE99F83CCAA4B7');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE referral RENAME TO tribute');
        $this->addSql('ALTER INDEX IDX_73079D009F12C49A RENAME TO idx_e21d2ea69f12c49a');
        $this->addSql('ALTER INDEX UNIQ_73079D007808B1AD RENAME TO uniq_e21d2ea67808b1ad');
        $this->addSql('ALTER TABLE affiliate_payout RENAME COLUMN referral_id TO tribute_id');
        $this->addSql('ALTER INDEX IDX_C1FE99F83CCAA4B7 RENAME TO idx_c1fe99f8d3b581f5');
    }
}
