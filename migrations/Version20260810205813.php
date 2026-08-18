<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810205813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize competition country codes to lowercase (legacy imports stored uppercase, breaking the case-sensitive country filter)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE competition SET location_country_code = LOWER(location_country_code) WHERE location_country_code <> LOWER(location_country_code)");
        $this->addSql("UPDATE competition_series SET location_country_code = LOWER(location_country_code) WHERE location_country_code <> LOWER(location_country_code)");
    }

    public function down(Schema $schema): void
    {
        // Lowercasing is intentionally irreversible — original casing carried no meaning.
    }
}
