<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * An address the receiving side refused for good (an SES permanent bounce): no notification is sent to it any more
 * and a player with this address sees a banner asking for a working one. Transactional mail is still tried.
 * Keyed by the address, not by a player - it covers guests (newsletter) too, and a player who changes the address
 * is free of it at once. Listmonk blocklists the address on its own for the newsletter.
 */
#[Entity]
class EmailSuppression
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        /** Lowercased and trimmed */
        #[Immutable]
        #[Column(length: 320, unique: true)]
        public string $email,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $suppressedAt,
        /** SES bounceSubType: General, NoEmail, Suppressed, OnAccountSuppressionList, UnsubscribedRecipient */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 50, nullable: true)]
        public null|string $bounceSubType,
        /** The receiving server's answer, e.g. "smtp; 550 5.1.1 user unknown" */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $diagnosticCode,
        /** What we were sending when it bounced (email_audit_log.email_type, or "newsletter") */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 100, nullable: true)]
        public null|string $emailType,
    ) {
    }

    public static function canonicalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * The address bounced again - keep the latest reason
     */
    public function bouncedAgain(DateTimeImmutable $at, null|string $bounceSubType, null|string $diagnosticCode, null|string $emailType): void
    {
        $this->suppressedAt = $at;
        $this->bounceSubType = $bounceSubType;
        $this->diagnosticCode = $diagnosticCode;
        $this->emailType = $emailType;
    }
}
