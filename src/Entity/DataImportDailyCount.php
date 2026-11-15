<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * How many results one player's imports **made** on one day (UTC) - on their own profile (`partner` = the importing
 * player themself) and on each registered partner's (docs/features/data-import/people.md "Limits"). What the daily
 * limits count: it only ever grows - an Undo takes the results back, never the count, so "add 200, undo, add 200
 * again" stays 200 for the day.
 *
 * It also carries the partner's one notification of the day: a later "Add" of the same day moves that notification
 * instead of adding one.
 *
 * Written by one statement (DataImportDailyCountRepository::made()), in the transaction that writes the result.
 */
#[Entity]
#[UniqueConstraint(columns: ['importer_id', 'partner_id', 'day'])]
#[Index(columns: ['partner_id', 'day'])]
#[Index(columns: ['notification_id'])]
class DataImportDailyCount
{
    /** The partner's notification of that day - a plain id: it may be deleted (read ones are pruned) */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $notificationId = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $importer,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $partner,
        #[Immutable]
        #[Column(type: Types::DATE_IMMUTABLE)]
        public DateTimeImmutable $day,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public int $results,
    ) {
    }

    public function toldBy(UuidInterface $notificationId): void
    {
        $this->notificationId = $notificationId;
    }
}
