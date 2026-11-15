<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportDailyCount;

/**
 * What the imports' daily limits count (docs/features/data-import/people.md "Limits"): results **made** per importing
 * player, registered person and UTC day - never lowered by an Undo.
 */
readonly final class DataImportDailyCountRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public static function dayOf(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
    }

    /**
     * A result of the importing player's import was made with these registered people on it (the importing player
     * among them - their own row is their total of the day). One statement per person, and race-safe: the count is
     * raised in the database, in the transaction that writes the result - a row that fails takes it back with it.
     * A genuine counter: it must not be read, changed and written back as an object.
     *
     * @param list<string> $memberPlayerIds
     */
    public function made(string $importerId, array $memberPlayerIds, DateTimeImmutable $now): void
    {
        foreach (array_unique([$importerId, ...$memberPlayerIds]) as $memberPlayerId) {
            $this->entityManager->getConnection()->executeStatement(
                <<<SQL
INSERT INTO data_import_daily_count (id, importer_id, partner_id, day, results)
VALUES (:id, :importerId, :partnerId, :day, 1)
ON CONFLICT (importer_id, partner_id, day) DO UPDATE SET results = data_import_daily_count.results + 1
SQL,
                ['id' => Uuid::uuid7()->toString(), 'importerId' => $importerId, 'partnerId' => $memberPlayerId, 'day' => self::dayOf($now)],
            );
        }
    }

    /**
     * For each of these players: the results that day's imports put on them - by this importing player, and by
     * everybody together (their own imports apart).
     *
     * @param list<string> $partnerIds
     *
     * @return array<string, array{fromImporter: int, fromEverybody: int}> by player id; nobody without a result
     */
    public function onPartners(string $importerId, array $partnerIds, DateTimeImmutable $now): array
    {
        if ($partnerIds === []) {
            return [];
        }

        /** @var list<array{partner_id: string, from_importer: int|string, from_everybody: int|string}> $rows */
        $rows = $this->entityManager->getConnection()->executeQuery(
            <<<SQL
SELECT
    partner_id,
    COALESCE(SUM(results) FILTER (WHERE importer_id = :importerId), 0) AS from_importer,
    SUM(results) AS from_everybody
FROM data_import_daily_count
WHERE day = :day
    AND partner_id IN (:partnerIds)
    AND importer_id <> partner_id
GROUP BY partner_id
SQL,
            ['importerId' => $importerId, 'day' => self::dayOf($now), 'partnerIds' => $partnerIds],
            ['partnerIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $byPartner = [];

        foreach ($rows as $row) {
            $byPartner[strtolower($row['partner_id'])] = ['fromImporter' => (int) $row['from_importer'], 'fromEverybody' => (int) $row['from_everybody']];
        }

        return $byPartner;
    }

    /**
     * The latest day this importing player's imports made results on the partner.
     */
    public function latest(string $importerId, string $partnerId): null|DataImportDailyCount
    {
        /** @var null|DataImportDailyCount $count */
        $count = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(DataImportDailyCount::class, 'c')
            ->where('c.importer = :importer')
            ->andWhere('c.partner = :partner')
            ->setParameter('importer', $importerId)
            ->setParameter('partner', $partnerId)
            ->orderBy('c.day', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $count;
    }
}
