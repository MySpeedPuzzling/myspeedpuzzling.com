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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\RoundResultChangeStatus;

/**
 * One change of official results the server took (RecordRoundResults): its `clientChangeId`, the device's own id of
 * the change. A change sent again with a known id is answered "unchanged" and never applied again - so a replay whose
 * first answer was lost cannot bring back a value somebody corrected since (the three-way check alone would: the
 * entry holds the change's `from` again). docs/features/competitions-management/official-results.md.
 *
 * Only changes that went through (applied) or found their value there already (unchanged) are kept; refused and
 * conflicting ones stay on the device and come back under a new id. Kept 90 days
 * (`myspeedpuzzling:prune-round-result-change-receipts`), gone with the round.
 */
#[Entity]
#[Index(columns: ['received_at'])]
class RoundResultChangeReceipt
{
    public function __construct(
        // The device's clientChangeId
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionRound $round,
        #[Immutable]
        #[Column(length: 16, enumType: RoundResultChangeStatus::class)]
        public RoundResultChangeStatus $status,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $receivedAt,
    ) {
    }
}
