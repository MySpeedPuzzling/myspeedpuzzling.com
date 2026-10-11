<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011003259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Badge: index (type, tier, earned_at, id) for the medal serial and the newest-first holders lists';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_fef0481d8cde5729');
        $this->addSql('CREATE INDEX IDX_FEF0481D8CDE5729249E978AC4DC16FBF396750 ON badge (type, tier, earned_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_FEF0481D8CDE5729249E978AC4DC16FBF396750');
        $this->addSql('CREATE INDEX idx_fef0481d8cde5729 ON badge (type)');
    }
}
