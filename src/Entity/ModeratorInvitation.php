<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * An admin asks a player to become a community moderator (docs/features/community-moderators.md). Nothing changes
 * until the player has gone through the moderator guide and agreed to its pledge - accepting records when and to
 * which version of the guide, and only then grants the role. Rows are kept after the answer: the history of who
 * was asked, by whom, and what they said.
 */
#[Entity]
class ModeratorInvitation
{
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $answeredAt = null;

    // null = no answer yet (still pending, or withdrawn by an admin)
    #[Column(nullable: true)]
    public null|bool $accepted = null;

    // Which guide the player agreed to (ModeratorGuide::VERSION) - only on an accepted invitation
    #[Column(type: Types::STRING, length: 20, nullable: true)]
    public null|string $agreedGuideVersion = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $withdrawnAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        // The admin who sent it - kept as null when their player is deleted
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $invitedBy,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $invitedAt,
    ) {
    }

    public function isPending(): bool
    {
        return $this->answeredAt === null && $this->withdrawnAt === null;
    }

    public function accept(string $guideVersion, DateTimeImmutable $at): void
    {
        $this->accepted = true;
        $this->agreedGuideVersion = $guideVersion;
        $this->answeredAt = $at;
    }

    public function decline(DateTimeImmutable $at): void
    {
        $this->accepted = false;
        $this->answeredAt = $at;
    }

    public function withdraw(DateTimeImmutable $at): void
    {
        $this->withdrawnAt = $at;
    }
}
