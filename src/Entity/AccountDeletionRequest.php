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
 * Pending "delete my account" confirmation. Same split-token shape as
 * ResetPasswordRequest: the e-mailed token is selector + verifier, only the
 * selector is queryable and only the verifier's hash is stored, so a DB leak
 * alone can never forge a link that deletes somebody's account.
 *
 * The row is consumed implicitly: confirming deletes the account, and the
 * request rows cascade away with it.
 */
#[Entity]
class AccountDeletionRequest
{
    /** Single source for the expiry the row carries and the expiry the e-mail promises */
    public const int LIFETIME_MINUTES = 60;

    public const string LIFETIME = '+' . self::LIFETIME_MINUTES . ' minutes';

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
        public string $selector,
        #[Immutable]
        #[Column]
        public string $hashedVerifier,
        #[Immutable]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $requestedAt,
        #[Immutable]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $expiresAt,
    ) {
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
