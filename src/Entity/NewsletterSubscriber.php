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
use SpeedPuzzling\Web\Value\NewsletterSubscriberStatus;

/**
 * Newsletter subscription of a visitor without a player account (public footer
 * form, double opt-in). Registered players are not stored here - their
 * subscription lives on Player::$newsletterEnabled. When an e-mail belongs to
 * both, the player record wins during the Listmonk sync.
 */
#[Entity]
class NewsletterSubscriber
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: NewsletterSubscriberStatus::class, options: ['default' => 'pending'])]
    public NewsletterSubscriberStatus $status = NewsletterSubscriberStatus::Pending;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $confirmedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $unsubscribedAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        /** Always stored lowercased and trimmed */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(unique: true)]
        public string $email,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $locale,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        /** Consent audit trail for the double opt-in request */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $ipAddress,
    ) {
    }

    public function confirm(DateTimeImmutable $now): void
    {
        $this->status = NewsletterSubscriberStatus::Confirmed;
        $this->confirmedAt = $now;
    }

    public function unsubscribe(DateTimeImmutable $now): void
    {
        $this->status = NewsletterSubscriberStatus::Unsubscribed;
        $this->unsubscribedAt = $now;
    }

    /**
     * A repeated signup from the public form: refresh the locale and consent
     * context and require a fresh opt-in confirmation, but never downgrade an
     * already confirmed subscription.
     */
    public function startNewOptIn(string $locale, null|string $ipAddress): void
    {
        $this->locale = $locale;
        $this->ipAddress = $ipAddress;

        if ($this->status !== NewsletterSubscriberStatus::Confirmed) {
            $this->status = NewsletterSubscriberStatus::Pending;
        }
    }
}
