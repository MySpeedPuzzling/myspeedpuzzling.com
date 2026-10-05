<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Puzzle names, phase 3 (docs/features/puzzle-names/): what the reporter of a merge request said each reported
 * puzzle's name is in - puzzle id => base language, empty for every request filed so far. A constant default, so no
 * table rewrite; lock_timeout caps the wait for the ACCESS EXCLUSIVE lock of ADD COLUMN.
 */
final class Version20261004232626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle merge requests: the reported name languages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('ALTER TABLE puzzle_merge_request ADD reported_name_languages JSONB DEFAULT \'{}\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_merge_request DROP reported_name_languages');
    }
}
