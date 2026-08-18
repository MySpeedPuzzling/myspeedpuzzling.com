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
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\OauthProvider;

/**
 * One linked third-party sign-in identity (decision D13, auth-migration README
 * §Auth-method extensibility): one row per (provider, provider subject), N rows
 * per account. Provenance lives here only - the account's user_id stays a
 * provider-agnostic `msp|<uuid7>` string, so linking and unlinking never touch
 * the Player.userId seam.
 */
#[Entity]
#[Table(name: 'oauth_identity')]
#[UniqueConstraint(columns: ['provider', 'provider_user_id'])]
class OauthIdentity
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        // DB-level cascade so a GDPR account deletion takes the identities with it
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public UserAccount $userAccount,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: OauthProvider::class)]
        public OauthProvider $provider,
        // The provider's stable subject id - the login key from the second visit on
        #[Immutable]
        #[Column(length: 255)]
        public string $providerUserId,
        // What the provider reported at link time, for support/debugging only
        // (Apple private-relay addresses land here as-is). Nullable: explicit
        // linking from settings (rule 5) needs no email, and Facebook users can
        // deny the email permission.
        #[Immutable]
        #[Column(length: 320, nullable: true)]
        public null|string $emailAtLink,
        #[Immutable]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $linkedAt,
        // House audit pattern, same as PAT/OAuth2 tokens
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $lastUsedAt = null,
    ) {
    }

    public function markUsed(DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }
}
