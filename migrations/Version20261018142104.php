<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018142104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE puzzle_image_analysis_claim (id UUID NOT NULL, analyzer VARCHAR(32) NOT NULL, worker VARCHAR(64) NOT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C0FF0F87D9816812 ON puzzle_image_analysis_claim (puzzle_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C0FF0F87D4508325D9816812 ON puzzle_image_analysis_claim (analyzer, puzzle_id)');
        $this->addSql('ALTER TABLE puzzle_image_analysis_claim ADD CONSTRAINT FK_C0FF0F87D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('DROP INDEX uniq_723ba7d2d9816812d4508325');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_723BA7D2D4508325D9816812 ON puzzle_image_analysis (analyzer, puzzle_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_image_analysis_claim DROP CONSTRAINT FK_C0FF0F87D9816812');
        $this->addSql('DROP TABLE puzzle_image_analysis_claim');
        $this->addSql('DROP INDEX UNIQ_723BA7D2D4508325D9816812');
        $this->addSql('CREATE UNIQUE INDEX uniq_723ba7d2d9816812d4508325 ON puzzle_image_analysis (puzzle_id, analyzer)');
    }
}
