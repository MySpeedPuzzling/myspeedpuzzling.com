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

/**
 * "Could I borrow these?" - one asker to one owner, for one or more puzzles (BorrowRequestItem), from one send of the
 * ask-to-borrow sheet (docs/features/wishlist-availability.md). It only asks: the owner answers per puzzle, and the box
 * changing hands is recorded by the existing lend/borrow flow. Nothing here assumes the owner is the asker's favorite -
 * who may be asked is decided by GetBorrowCandidates alone.
 *
 * send_id makes a send idempotent: a resent form finds its requests instead of asking anybody twice.
 */
#[Entity]
#[UniqueConstraint(name: 'borrow_request_send_owner_unique', columns: ['send_id', 'owner_id'])]
#[Index(name: 'borrow_request_asker_created', columns: ['asker_id', 'created_at'])]
#[Index(name: 'borrow_request_owner_created', columns: ['owner_id', 'created_at'])]
class BorrowRequest
{
    public const int NOTE_MAX_LENGTH = 200;
    public const int EXPIRES_AFTER_DAYS = 30;

    #[Immutable]
    #[Column(length: self::NOTE_MAX_LENGTH, nullable: true)]
    public null|string $note;

    #[Immutable]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $expiresAt;

    // The owner opened a chat from the request (a "yes", or "Reply in chat")
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $repliedAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $asker,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $owner,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $sendId,
        null|string $note,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
        $note = $note !== null ? trim($note) : null;

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A borrow request note is at most %d characters.', self::NOTE_MAX_LENGTH));
        }

        $this->note = $note === '' ? null : $note;
        $this->expiresAt = $createdAt->modify(sprintf('+%d days', self::EXPIRES_AFTER_DAYS));
    }

    public function isReplied(): bool
    {
        return $this->repliedAt !== null;
    }

    /**
     * Replying again keeps the first moment.
     */
    public function markReplied(DateTimeImmutable $now): void
    {
        $this->repliedAt ??= $now;
    }
}
