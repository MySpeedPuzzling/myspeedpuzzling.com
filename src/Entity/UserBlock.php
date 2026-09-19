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
use SpeedPuzzling\Web\Value\UserBlockSource;

/**
 * "The blocker must not see the blocked player" - one row, one direction. Site-wide
 * (see docs/features/player-blocklist.md), not just messaging.
 */
#[Entity]
#[UniqueConstraint(columns: ['blocker_id', 'blocked_id'])]
#[Index(columns: ['blocker_id'])]
class UserBlock
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $blocker,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $blocked,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $blockedAt,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: UserBlockSource::class, options: ['default' => UserBlockSource::Self->value])]
        public UserBlockSource $source = UserBlockSource::Self,
        #[Immutable]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $note = null,
    ) {
    }
}
