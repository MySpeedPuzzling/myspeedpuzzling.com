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

/**
 * One change set of the participants spreadsheet the server took (ApplyParticipantSheetChanges), under the page's own
 * `changesetId`. The same id sent again (an answer lost on the way) is answered from here and never applied again - so
 * a replay arriving after the organiser corrected the value cannot bring the old one back (the three-way check alone
 * would let it: the cell holds the change's `from` again). The RoundResultChangeReceipt rule, per change set.
 * docs/features/competitions-management/participants-spreadsheet.md.
 *
 * `outcomes` is the answer's group list as it was given, `versionBefore` / `versionAfter` the sheet state versions
 * around the write. Kept 90 days (`myspeedpuzzling:prune-round-result-change-receipts`), gone with the event.
 */
#[Entity]
#[Index(columns: ['received_at'])]
class ParticipantSheetChangeReceipt
{
    /**
     * @param list<array<string, mixed>> $outcomes
     */
    public function __construct(
        // The page's changesetId
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Competition $competition,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $receivedAt,
        #[Immutable]
        #[Column(type: Types::JSON)]
        public array $outcomes,
        #[Immutable]
        #[Column(length: 64)]
        public string $versionBefore,
        #[Immutable]
        #[Column(length: 64)]
        public string $versionAfter,
    ) {
    }
}
