<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;

readonly final class ManufacturerRepository
{
    // Read by Postgres and PCRE alike (ManufacturerResolver::cleanName()): ASCII whitespace only - their ideas of
    // Unicode whitespace differ, and no byte of a multibyte UTF-8 character ever matches
    public const string WHITESPACE_PATTERN = '[ \t\n\r]+';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     */
    public function get(string $manufacturerId): Manufacturer
    {
        $uuid = Uuid::fromString($manufacturerId);

        $manufacturer = $this->entityManager->find(Manufacturer::class, $uuid);

        return $manufacturer ?? throw new ManufacturerNotFound();
    }

    public function slugExists(string $slug): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(manufacturer.id)')
            ->from(Manufacturer::class, 'manufacturer')
            ->where('manufacturer.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * Is there an approved brand of this name (case-insensitive) other than the given ones?
     *
     * @param list<UuidInterface> $exceptIds
     */
    public function approvedNameExists(string $name, array $exceptIds): bool
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('COUNT(manufacturer.id)')
            ->from(Manufacturer::class, 'manufacturer')
            ->where('manufacturer.approved = true')
            ->andWhere('LOWER(TRIM(manufacturer.name)) = :name')
            ->setParameter('name', mb_strtolower(trim($name)));

        if ($exceptIds !== []) {
            $queryBuilder
                ->andWhere('manufacturer.id NOT IN (:exceptIds)')
                ->setParameter(
                    'exceptIds',
                    array_map(static fn (UuidInterface $id): string => $id->toString(), $exceptIds),
                    ArrayParameterType::STRING,
                );
        }

        return (int) $queryBuilder->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * The brand a typed name means: the same name ignoring case and spacing - trimmed, inner whitespace
     * collapsed, lowercased, on both sides (no other normalisation: "Puzzle Bug" is not "PuzzleBug").
     * Approved, unapproved, whoever added it. Among several: approved first, then the one with the most
     * puzzles, then the oldest. Both sides go through the same SQL expression, so they always agree.
     * A sequential scan of ~2,300 short names - no index needed.
     */
    public function findByNameIgnoringCase(string $name): null|Manufacturer
    {
        $query = <<<SQL
SELECT manufacturer.id
FROM manufacturer
WHERE LOWER(BTRIM(REGEXP_REPLACE(manufacturer.name, :whitespace, ' ', 'g')))
    = LOWER(BTRIM(REGEXP_REPLACE(:name, :whitespace, ' ', 'g')))
ORDER BY
    manufacturer.approved DESC,
    (SELECT COUNT(*) FROM puzzle WHERE puzzle.manufacturer_id = manufacturer.id) DESC,
    manufacturer.added_at ASC NULLS FIRST,
    manufacturer.id ASC
LIMIT 1
SQL;

        $id = $this->entityManager->getConnection()->fetchOne($query, [
            'name' => $name,
            'whitespace' => self::WHITESPACE_PATTERN,
        ]);

        if (!is_string($id)) {
            return null;
        }

        return $this->entityManager->find(Manufacturer::class, Uuid::fromString($id));
    }

    public function save(Manufacturer $manufacturer): void
    {
        $this->entityManager->persist($manufacturer);
    }

    public function delete(Manufacturer $manufacturer): void
    {
        $this->entityManager->remove($manufacturer);
    }
}
