<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Puzzle names, phase 3 (docs/features/puzzle-names/): a change request proposes the other names and the main title's
 * language (null = the names are no part of the proposal) and snapshots them as they were when proposed - the names
 * are applied as a diff on approval. Nullable columns without a default, so no table rewrite; lock_timeout caps the
 * wait for the ACCESS EXCLUSIVE lock of ADD COLUMN.
 */
final class Version20261004233750 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Puzzle change requests: proposed and original other names with the main title\'s language';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('ALTER TABLE puzzle_change_request ADD proposed_alternative_names JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD proposed_name_language VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD original_alternative_names JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD original_name_language VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_change_request DROP proposed_alternative_names');
        $this->addSql('ALTER TABLE puzzle_change_request DROP proposed_name_language');
        $this->addSql('ALTER TABLE puzzle_change_request DROP original_alternative_names');
        $this->addSql('ALTER TABLE puzzle_change_request DROP original_name_language');
    }
}
