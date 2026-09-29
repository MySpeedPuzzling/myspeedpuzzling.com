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
 *
 * Since the auth UX redesign phase 2 the same mail also carries a 6-digit code
 * (docs/features/auth-ux-redesign.md §4.4) for the browser that asked for it -
 * the in-app browser case, where the link would open the phone's own browser.
 * Link and code are one sign-in: whichever is used first consumes the row.
 * Only an HMAC of the code is stored (SignInCodeHasher), keyed with the row id.
 */
#[Entity]
class LoginLinkRequest
{
    public const int MAX_CODE_ATTEMPTS = 5;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $consumedAt = null;

    /**
     * Set when the code (not the link) signed in. The link's scanner grace
     * window (SingleUseLoginLinkHandler) does not apply then: the one sign-in
     * of this request already happened, in the browser that asked for it.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $codeUsedAt = null;

    /** Wrong codes typed for this request; at MAX_CODE_ATTEMPTS the code is dead (the link lives on) */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $codeFailedAttempts = 0;

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
        /**
         * Where the visitor was headed when they asked for the link (the login
         * page's ?return=, already validated by ReturnUrl). Kept here rather than
         * in the link itself, so the signed URL carries no unsigned parameter.
         * Re-validated when the link is used.
         */
        #[Immutable]
        #[Column(length: 2048, nullable: true)]
        public null|string $returnPath = null,
        /**
         * HMAC-SHA256 of the 6-digit code (SignInCodeHasher). Null for a link
         * issued without a code (anything but the "Email me a sign-in code" form).
         */
        #[Immutable]
        #[Column(length: 64, nullable: true)]
        public null|string $codeHash = null,
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

    public function consumeWithCode(DateTimeImmutable $now): void
    {
        $this->consumedAt = $now;
        $this->codeUsedAt = $now;
    }

    public function recordWrongCode(): void
    {
        $this->codeFailedAttempts++;
    }

    public function codeAttemptsLeft(): int
    {
        return max(0, self::MAX_CODE_ATTEMPTS - $this->codeFailedAttempts);
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }
}
