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
use SpeedPuzzling\Web\Value\PartnerKind;

/**
 * "I'd like to puzzle with you" sent to a partner finder listing (docs/features/partner-finder.md D6) - once per listing
 * and sender. The receiver's Reply starts a conversation from their side; the sender only ever sees "Sent".
 * The (from_player_id, created_at) index serves the per-sender daily limit.
 */
#[Entity]
#[UniqueConstraint(name: 'partner_request_listing_sender_unique', columns: ['listing_id', 'from_player_id'])]
#[Index(name: 'partner_request_sender_created', columns: ['from_player_id', 'created_at'])]
class PartnerRequest
{
    public const int NOTE_MAX_LENGTH = 200;

    // The receiver replied (a conversation was started from their side)
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $repliedAt = null;

    #[Immutable]
    #[Column(length: self::NOTE_MAX_LENGTH, nullable: true)]
    public null|string $note;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public PartnerListing $listing,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $fromPlayer,
        #[Immutable]
        #[Column(enumType: PartnerKind::class)]
        public PartnerKind $kind,
        null|string $note,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
        $note = $note !== null ? trim($note) : null;

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A partner request note is at most %d characters.', self::NOTE_MAX_LENGTH));
        }

        $this->note = $note === '' ? null : $note;
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
