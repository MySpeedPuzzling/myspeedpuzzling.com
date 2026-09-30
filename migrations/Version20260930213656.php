<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930213656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'First-try integrity: "it\'s fine, hide this" dismissals of late first tries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE first_try_review_dismissal (id UUID NOT NULL, dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, solving_time_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9A1F51A299E6F5DF ON first_try_review_dismissal (player_id)');
        $this->addSql('CREATE INDEX IDX_9A1F51A2D6B05C60 ON first_try_review_dismissal (solving_time_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9A1F51A299E6F5DFD6B05C60 ON first_try_review_dismissal (player_id, solving_time_id)');
        $this->addSql('ALTER TABLE first_try_review_dismissal ADD CONSTRAINT FK_9A1F51A299E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE first_try_review_dismissal ADD CONSTRAINT FK_9A1F51A2D6B05C60 FOREIGN KEY (solving_time_id) REFERENCES puzzle_solving_time (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE first_try_review_dismissal DROP CONSTRAINT FK_9A1F51A299E6F5DF');
        $this->addSql('ALTER TABLE first_try_review_dismissal DROP CONSTRAINT FK_9A1F51A2D6B05C60');
        $this->addSql('DROP TABLE first_try_review_dismissal');
    }
}
