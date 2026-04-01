<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
#[Index(name: 'idx_personal_access_token_player', columns: ['player_id'])]
class PersonalAccessToken
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|DateTimeImmutable $lastUsedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|DateTimeImmutable $revokedAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[Immutable]
        public Player $player,
        #[Column(length: 100)]
        #[Immutable]
        public string $name,
        #[Column(length: 64, unique: true)]
        #[Immutable]
        public string $tokenHash,
        #[Column(length: 16)]
        #[Immutable]
        public string $tokenPrefix,
        #[Column]
        #[Immutable]
        public DateTimeImmutable $fairUsePolicyAcceptedAt,
        #[Column]
        #[Immutable]
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function revoke(): void
    {
        $this->revokedAt = new DateTimeImmutable();
    }

    public function updateLastUsedAt(): void
    {
        $this->lastUsedAt = new DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }
}
