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
 * A player's taste (the candidate prediction model's taste factor, v5 - docs/features/puzzle-intelligence/README.md
 * "Step 8", "The taste factor (v5)") as of right after one of their first tries in the candidate's fit window: the
 * ridge solution's sufficient statistics (Value\TasteState, jsonb) valid from that first try's moment (its solve,
 * then its tracking moment - the order every as-of read of the models uses). The live prediction reads the latest row
 * of the player, a reconstruction the latest before its solve. Rewritten by the candidate's daily fit (the model's W
 * changes every day; CandidateModelRecalculator) in SQL; never shown. No foreign key, like the candidate's other
 * tables: a deleted player's rows go with the next fit.
 */
#[Entity]
class CandidatePlayerTaste
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $playerId,
        #[Id]
        #[Immutable]
        #[Column]
        public DateTimeImmutable $validFrom,
        #[Id]
        #[Immutable]
        #[Column]
        public DateTimeImmutable $validFromTrackedAt,
        #[Immutable]
        #[Column]
        public int $firstTries,
        #[Immutable]
        #[Column(type: Types::JSONB)]
        public array $state,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
