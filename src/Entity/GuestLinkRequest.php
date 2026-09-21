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
 * "This guest of mine is you": a player asks a registered player to take the place of a guest (a name
 * without an account) in the pairs/teams they share. Nothing changes until the asked player agrees -
 * accepting puts those results into their own history (docs/features/pairs-and-teams/README.md).
 */
#[Entity]
#[Index(columns: ['requester_id', 'guest_key'])]
class GuestLinkRequest
{
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $resolvedAt = null;

    // null = waiting for an answer
    #[Column(nullable: true)]
    public null|bool $accepted = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $requester,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $target,
        // Member key of the guest ("g:" + normalised name) in the requester's pairs/teams
        #[Immutable]
        #[Column(type: Types::STRING)]
        public string $guestKey,
        #[Immutable]
        #[Column(type: Types::STRING)]
        public string $guestName,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $requestedAt,
    ) {
    }

    public function isPending(): bool
    {
        return $this->resolvedAt === null;
    }

    public function resolve(bool $accepted, DateTimeImmutable $at): void
    {
        $this->accepted = $accepted;
        $this->resolvedAt = $at;
    }
}
