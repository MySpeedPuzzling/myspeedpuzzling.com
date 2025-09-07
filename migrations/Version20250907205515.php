<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250907205515 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CASCADE deletion to collection_item.collection_id foreign key';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE collection_item DROP CONSTRAINT FK_556C09F0514956FD');
        $this->addSql('ALTER TABLE collection_item ADD CONSTRAINT FK_556C09F0514956FD FOREIGN KEY (collection_id) REFERENCES collection (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE collection_item DROP CONSTRAINT fk_556c09f0514956fd');
        $this->addSql('ALTER TABLE collection_item ADD CONSTRAINT fk_556c09f0514956fd FOREIGN KEY (collection_id) REFERENCES collection (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
