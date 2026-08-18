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
use Doctrine\ORM\Mapping\Table;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\AuthAuditEventType;

#[Entity]
#[Table(name: 'auth_audit_log')]
#[Index(columns: ['user_account_id', 'occurred_at'])]
#[Index(columns: ['occurred_at'])]
#[Index(columns: ['event_type', 'occurred_at'])]
class AuthAuditEvent
{
    /**
     * @param null|array<string, mixed> $metadata never secrets, never passwords
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        // Nullable: failed logins for unknown emails have no account. DB-level
        // cascade so a GDPR account deletion takes the audit trail with it.
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|UserAccount $userAccount,
        #[Immutable]
        #[Column(length: 320, nullable: true)]
        public null|string $email,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: AuthAuditEventType::class)]
        public AuthAuditEventType $eventType,
        #[Immutable]
        #[Column(length: 50, nullable: true)]
        public null|string $authenticator,
        #[Immutable]
        #[Column(length: 45, nullable: true)]
        public null|string $ipAddress,
        #[Immutable]
        #[Column(length: 500, nullable: true)]
        public null|string $userAgent,
        #[Immutable]
        #[Column(type: Types::JSONB, nullable: true)]
        public null|array $metadata,
        #[Immutable]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
