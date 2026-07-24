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
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[Entity]
class UserAccount implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $password = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $emailVerifiedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $lastLoginAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::BOOLEAN, options: ['default' => false])]
    public bool $legacyAuth0 = false;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(unique: true)]
        public string $userId,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(unique: true)]
        public string $email,
        #[Immutable]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $registeredAt,
    ) {
        $this->email = self::canonicalizeEmail($email);
    }

    public static function canonicalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Upsert step of the Auth0 import — idempotent, safe to re-run with fresher exports.
     * State the user has since set natively must never be regressed by a stale export:
     * a password that is no longer a bcrypt hash was re-hashed or replaced locally, and
     * an account with any native activity keeps its (possibly changed) email + verified flag.
     */
    public function applyAuth0Import(
        string $email,
        null|string $bcryptPasswordHash,
        bool $emailVerified,
        DateTimeImmutable $now,
    ): void {
        $this->legacyAuth0 = true;

        $hasNativeActivity = $this->lastLoginAt !== null
            || ($this->password !== null && !str_starts_with($this->password, '$2'));

        if (!$hasNativeActivity) {
            $this->email = self::canonicalizeEmail($email);

            if ($emailVerified && $this->emailVerifiedAt === null) {
                $this->emailVerifiedAt = $now;
            }
        }

        if (
            $bcryptPasswordHash !== null
            && ($this->password === null || str_starts_with($this->password, '$2'))
        ) {
            $this->password = $bcryptPasswordHash;
        }
    }

    public function changePassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function markEmailVerified(DateTimeImmutable $now): void
    {
        if ($this->emailVerifiedAt === null) {
            $this->emailVerifiedAt = $now;
        }
    }

    public function changeEmail(string $email): void
    {
        $email = self::canonicalizeEmail($email);

        if ($email === $this->email) {
            return;
        }

        $this->email = $email;
        // The new address is unproven until its verification link is clicked
        $this->emailVerifiedAt = null;
    }

    public function getUserIdentifier(): string
    {
        assert($this->userId !== '');

        return $this->userId;
    }

    /**
     * @return non-empty-list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): null|string
    {
        return $this->password;
    }
}
