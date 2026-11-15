<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * The spreadsheet import's one row of settings - the safety switch that pauses adding
 * (docs/features/data-import/adding.md "The switch", docs/features/feature_flags.md). In the database, flipped on
 * /admin/imports: an environment variable would need every container restarted.
 *
 * No row = never paused. While paused no "Add" starts and running batches wait after the row they are at; reading,
 * mapping, pairing and Undo stay open.
 */
#[Entity]
class DataImportSettings
{
    public const int ID = 1;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => false])]
    public bool $addingPaused = false;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $addingPausedChangedAt = null;

    /** The admin who flipped it last - a plain id, it outlives the account */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $addingPausedChangedBy = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $addingPausedReason = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $id = self::ID,
    ) {
    }

    public function changeAddingPaused(bool $paused, UuidInterface $by, null|string $reason, DateTimeImmutable $now): void
    {
        $this->addingPaused = $paused;
        $this->addingPausedChangedAt = $now;
        $this->addingPausedChangedBy = $by;
        $this->addingPausedReason = $reason;
    }
}
