<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Adds manufacturer.slug (nullable, unique) and backfills it for all existing
 * manufacturers in postUp() (lowercase ascii slug of the name; name collisions
 * get a -2/-3/... suffix, approved brands with the most puzzles win the clean slug).
 */
final class Version20260710230956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manufacturer slug column + backfill for brand hub landing pages';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE manufacturer ADD slug VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3D0AE6DC989D9B62 ON manufacturer (slug)');
    }

    public function postUp(Schema $schema): void
    {
        $slugger = new AsciiSlugger('en');

        /** @var list<array{id: string, name: string}> $manufacturers */
        $manufacturers = $this->connection->executeQuery(
            <<<SQL
            SELECT manufacturer.id, manufacturer.name
            FROM manufacturer
            LEFT JOIN puzzle ON puzzle.manufacturer_id = manufacturer.id
            WHERE manufacturer.slug IS NULL
            GROUP BY manufacturer.id
            ORDER BY manufacturer.approved DESC, COUNT(puzzle.id) DESC, manufacturer.id ASC
            SQL,
        )->fetchAllAssociative();

        /** @var array<string, true> $usedSlugs */
        $usedSlugs = [];

        /** @var list<string> $existingSlugs */
        $existingSlugs = $this->connection
            ->executeQuery('SELECT slug FROM manufacturer WHERE slug IS NOT NULL')
            ->fetchFirstColumn();

        foreach ($existingSlugs as $existingSlug) {
            $usedSlugs[$existingSlug] = true;
        }

        foreach ($manufacturers as $manufacturer) {
            $base = strtolower((string) $slugger->slug($manufacturer['name']));

            if ($base === '') {
                $base = 'brand-' . substr($manufacturer['id'], 0, 8);
            }

            $slug = $base;
            $suffix = 2;

            while (isset($usedSlugs[$slug])) {
                $slug = $base . '-' . $suffix;
                $suffix++;
            }

            $usedSlugs[$slug] = true;

            $this->connection->executeStatement(
                'UPDATE manufacturer SET slug = :slug WHERE id = :id',
                ['slug' => $slug, 'id' => $manufacturer['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_3D0AE6DC989D9B62');
        $this->addSql('ALTER TABLE manufacturer DROP slug');
    }
}
