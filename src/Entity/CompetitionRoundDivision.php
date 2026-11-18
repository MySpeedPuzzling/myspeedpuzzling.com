<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * A division of the event a round uses (shared-puzzles-and-divisions.md §3.2, D1): St. Paul's SOLO uses Regular, Senior
 * 55+ and Junior U16, PAIRS only the first two, the team rounds none. A round without rows has no divisions - its pages
 * and answers stay exactly as they were. A separate entity, so CompetitionRound itself stays untouched; both foreign
 * keys cascade (a deleted round or division takes its rows with it).
 */
#[Entity]
#[UniqueConstraint(name: 'competition_round_division_unique', columns: ['round_id', 'division_id'])]
class CompetitionRoundDivision
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionRound $round,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionDivision $division,
    ) {
    }
}
