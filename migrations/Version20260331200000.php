<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add partial indexes for puzzle intelligence recalculation performance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX custom_pst_intelligence ON puzzle_solving_time (player_id, puzzle_id) WHERE puzzling_type = \'solo\' AND suspicious = false AND seconds_to_solve IS NOT NULL');
        $this->addSql('CREATE INDEX custom_pst_intelligence_first_attempt ON puzzle_solving_time (puzzle_id, player_id) WHERE first_attempt = true AND puzzling_type = \'solo\' AND suspicious = false AND seconds_to_solve IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS custom_pst_intelligence');
        $this->addSql('DROP INDEX IF EXISTS custom_pst_intelligence_first_attempt');
    }
}
