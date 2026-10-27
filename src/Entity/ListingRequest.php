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
 * "Could I have these?" - one buyer to one seller, for one or more of the seller's listings (ListingRequestItem), from
 * one send of the listing request sheet (docs/features/marketplace/12-listing-requests.md). The seller answers per
 * listing; a yes reserves it for the buyer, the sale itself is recorded by the existing "Mark as sold" flow. The buyer may
 * offer puzzles of their own sell/swap list in exchange (ListingRequestOffer).
 *
 * send_id makes a send idempotent: a resent form finds its requests instead of asking anybody twice.
 */
#[Entity]
#[UniqueConstraint(name: 'listing_request_send_seller_unique', columns: ['send_id', 'seller_id'])]
#[Index(name: 'listing_request_buyer_created', columns: ['buyer_id', 'created_at'])]
#[Index(name: 'listing_request_seller_created', columns: ['seller_id', 'created_at'])]
class ListingRequest
{
    // Room for a shipping question or an offer - a borrow request note is 200
    public const int NOTE_MAX_LENGTH = 500;
    // Listings change hands faster than collections - waiting listings expire after two weeks
    public const int EXPIRES_AFTER_DAYS = 14;

    #[Immutable]
    #[Column(length: self::NOTE_MAX_LENGTH, nullable: true)]
    public null|string $note;

    #[Immutable]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $expiresAt;

    // The seller opened a chat from the request (a "yes", or "Reply in chat")
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
        public Player $buyer,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $seller,
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
            throw new \InvalidArgumentException(sprintf('A listing request note is at most %d characters.', self::NOTE_MAX_LENGTH));
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
