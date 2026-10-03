<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003113151 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player ALTER comparison_view SET DEFAULT \'table\'');
        // Everyone, on purpose: the compare page went live hours ago, nobody chose a view deliberately yet, and the
        // Duel view is gone (no 'duel' may survive - the enum case no longer exists). Table is the new default.
        $this->addSql('UPDATE player SET comparison_view = \'table\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player ALTER comparison_view SET DEFAULT \'cards\'');
    }
}
