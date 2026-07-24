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
 * Consumption record for a magic sign-in link (D18): Symfony's login_link is
 * signature + expiry only, so a link stays replayable for its whole lifetime.
 * Every issued link gets a row here (only the sha256 of the link signature is
 * stored — a DB leak alone can never forge a usable link) and the row is
 * consumed on the first successful login. Same shape as ResetPasswordRequest.
 */
#[Entity]
class LoginLinkRequest
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $consumedAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public UserAccount $userAccount,
        #[Immutable]
        #[Column(unique: true)]
        public string $hashedToken,
        #[Immutable]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $requestedAt,
        #[Immutable]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $expiresAt,
    ) {
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function consume(DateTimeImmutable $now): void
    {
        $this->consumedAt = $now;
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }
}
