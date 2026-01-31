<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260131100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create voucher table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE voucher (
            id UUID NOT NULL,
            code VARCHAR(32) NOT NULL,
            months_value INT NOT NULL,
            valid_until TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            used_by_id UUID DEFAULT NULL,
            internal_note TEXT DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1392A5D877153098 ON voucher (code)');
        $this->addSql('CREATE INDEX IDX_1392A5D829F6EE60 ON voucher (used_by_id)');
        $this->addSql('COMMENT ON COLUMN voucher.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN voucher.used_by_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN voucher.valid_until IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN voucher.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN voucher.used_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE voucher ADD CONSTRAINT FK_1392A5D829F6EE60 FOREIGN KEY (used_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voucher DROP CONSTRAINT FK_1392A5D829F6EE60');
        $this->addSql('DROP TABLE voucher');
    }
}
