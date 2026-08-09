<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809210908 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WJPF pairing: wjpf_identity maps players to worldjigsawpuzzle.org accounts. '
            . 'wjpf_id is intentionally non-unique - duplicate claims are real data worth keeping '
            . 'and must not abort a multi-hour backfill.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE wjpf_identity (wjpf_id VARCHAR(64) DEFAULT NULL, wjpf_name_url VARCHAR(255) DEFAULT NULL, conflicting_my_speed_puzzling_id VARCHAR(255) DEFAULT NULL, paired_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, claimed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, last_response JSON DEFAULT \'{}\' NOT NULL, id UUID NOT NULL, checked_email VARCHAR(320) NOT NULL, status VARCHAR(255) NOT NULL, checked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8A1126F999E6F5DF ON wjpf_identity (player_id)');
        $this->addSql('CREATE INDEX IDX_8A1126F9635349D5 ON wjpf_identity (wjpf_id)');
        $this->addSql('CREATE INDEX IDX_8A1126F97B00651C ON wjpf_identity (status)');
        $this->addSql('ALTER TABLE wjpf_identity ADD CONSTRAINT FK_8A1126F999E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE wjpf_identity DROP CONSTRAINT FK_8A1126F999E6F5DF');
        $this->addSql('DROP TABLE wjpf_identity');
    }
}
