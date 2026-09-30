<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930205637 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE moderation_action DROP CONSTRAINT fk_b05d8128642b8210');
        $this->addSql('ALTER TABLE moderation_action ALTER admin_id DROP NOT NULL');
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT FK_B05D8128642B8210 FOREIGN KEY (admin_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE moderation_action DROP CONSTRAINT FK_B05D8128642B8210');
        $this->addSql('ALTER TABLE moderation_action ALTER admin_id SET NOT NULL');
        $this->addSql('ALTER TABLE moderation_action ADD CONSTRAINT fk_b05d8128642b8210 FOREIGN KEY (admin_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
