<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260204234358 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_98197a65f851fd86 RENAME TO IDX_98197A65DAE23CE6');
        $this->addSql('ALTER INDEX idx_voucher_claim_voucher RENAME TO IDX_62D2881928AA1B6F');
        $this->addSql('ALTER INDEX idx_voucher_claim_player RENAME TO IDX_62D2881999E6F5DF');
        $this->addSql('ALTER INDEX uniq_voucher_player RENAME TO unique_voucher_player');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_98197a65dae23ce6 RENAME TO idx_98197a65f851fd86');
        $this->addSql('ALTER INDEX idx_62d2881928aa1b6f RENAME TO idx_voucher_claim_voucher');
        $this->addSql('ALTER INDEX idx_62d2881999e6f5df RENAME TO idx_voucher_claim_player');
        $this->addSql('ALTER INDEX unique_voucher_player RENAME TO uniq_voucher_player');
    }
}
