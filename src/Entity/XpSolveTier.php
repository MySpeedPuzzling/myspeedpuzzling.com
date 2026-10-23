<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * The difficulty tier a solve earned its XP with, per earning player - frozen at the first award with a rated
 * puzzle (or at its difficulty settlement). Every replay (the live wiring's scope rebuild and the full rebuild) reads
 * it instead of today's tier while the solve is still on the same puzzle: tiers drift with the community, and a
 * change of the player's results must never re-price the solves it only replays (docs/features/xp-levels/README.md
 * §Anti-farming).
 *
 * Kept apart from xp_entry on purpose - a full rebuild wipes the ledger, not this. Written with plain SQL by XpLedger
 * only; plain uuid columns without FKs like xp_entry (a deleted solve may come back with its id).
 */
#[Entity]
class XpSolveTier
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $playerId,
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $solvingTimeId,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $puzzleId,
        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $difficultyTier,
    ) {
    }
}
