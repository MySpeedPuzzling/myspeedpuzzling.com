<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260204212025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('COMMENT ON COLUMN voucher.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN voucher.valid_until IS \'\'');
        $this->addSql('COMMENT ON COLUMN voucher.created_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN voucher.used_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN voucher.used_by_id IS \'\'');
        $this->addSql('ALTER INDEX idx_1392a5d829f6ee60 RENAME TO IDX_1392A5D84C2B72A8');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('COMMENT ON COLUMN voucher.used_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN voucher.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN voucher.valid_until IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN voucher.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN voucher.used_by_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER INDEX idx_1392a5d84c2b72a8 RENAME TO idx_1392a5d829f6ee60');
    }
}
