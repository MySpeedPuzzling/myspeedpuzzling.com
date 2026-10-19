<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019200902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Event types (docs/features/events-page/event-types.md): an optional jsonb list on competition and competition_series, [] = not set';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition ADD event_types JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE competition_series ADD event_types JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competition DROP event_types');
        $this->addSql('ALTER TABLE competition_series DROP event_types');
    }
}
