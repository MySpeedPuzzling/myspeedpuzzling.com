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
use SpeedPuzzling\Web\Value\EmailSuppressionSource;

/**
 * An address the receiving side refused for good (an SES permanent bounce, or a domain that does not exist): no
 * notification is sent to it any more and a player with this address sees a banner asking for a working one.
 * Transactional mail is still tried - except for an address blocked by hand (source manual), which gets nothing.
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
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::STRING, length: 30, enumType: EmailSuppressionSource::class, options: ['default' => 'hard_bounce'])]
        public EmailSuppressionSource $source = EmailSuppressionSource::HardBounce,
        /** Blocked by hand: why, in words the player reads in the banner */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $reason = null,
    ) {
    }

    public static function blockedByHand(UuidInterface $id, string $email, DateTimeImmutable $at, string $reason): self
    {
        return new self($id, self::canonicalize($email), $at, null, null, null, EmailSuppressionSource::Manual, $reason);
    }

    /**
     * Nothing at all goes to the address, sign-in links included - only when blocked by hand
     */
    public function blocksAllMail(): bool
    {
        return $this->source === EmailSuppressionSource::Manual;
    }

    /**
     * Blocked by hand on top of a bounce (or again with a new reason): from now on nothing is sent
     */
    public function blockByHand(DateTimeImmutable $at, string $reason): void
    {
        $this->suppressedAt = $at;
        $this->source = EmailSuppressionSource::Manual;
        $this->reason = $reason;
    }

    public static function canonicalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * The address bounced again - keep the latest reason. A block by hand stays one: only the internal API lifts it
     */
    public function bouncedAgain(DateTimeImmutable $at, null|string $bounceSubType, null|string $diagnosticCode, null|string $emailType): void
    {
        if ($this->source !== EmailSuppressionSource::Manual) {
            $this->suppressedAt = $at;
        }

        $this->bounceSubType = $bounceSubType;
        $this->diagnosticCode = $diagnosticCode;
        $this->emailType = $emailType;
    }
}
