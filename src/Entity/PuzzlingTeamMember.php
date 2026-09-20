<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

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
 * One person of a PuzzlingTeam: a registered player, or a guest known by name only.
 *
 * The player foreign key deliberately restricts deletion - a member must never vanish under a
 * composition key, so DeletePlayerHandler turns the member into a guest first.
 */
#[Entity]
#[Index(columns: ['player_id'])]
#[UniqueConstraint(columns: ['team_id', 'member_key'])]
class PuzzlingTeamMember
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public PuzzlingTeam $team,
        // The player's id, or "g:" + normalised guest name - what the team's composition key is made of
        #[Column(type: Types::STRING)]
        public string $memberKey,
        #[ManyToOne]
        #[JoinColumn(onDelete: 'RESTRICT')]
        public null|Player $player,
        #[Column(type: Types::STRING, nullable: true)]
        public null|string $guestName,
        #[Column(type: Types::SMALLINT)]
        public int $position,
    ) {
    }
}
