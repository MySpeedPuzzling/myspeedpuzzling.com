<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\BounceType;
use SpeedPuzzling\Web\Value\EmailAuditStatus;

#[Entity]
#[Index(columns: ['recipient_email'])]
#[Index(columns: ['sent_at'])]
#[Index(columns: ['status'])]
class EmailAuditLog
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: EmailAuditStatus::class)]
    public EmailAuditStatus $status;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 255, nullable: true)]
    public null|string $messageId = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $errorMessage = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $smtpDebugLog = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: BounceType::class, nullable: true)]
    public null|BounceType $bounceType = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $bouncedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $bounceReason = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $sentAt,
        #[Immutable]
        #[Column(length: 320)]
        public string $recipientEmail,
        #[Immutable]
        #[Column(type: Types::TEXT)]
        public string $subject,
        #[Immutable]
        #[Column(length: 50)]
        public string $transportName,
        #[Immutable]
        #[Column(length: 100, nullable: true)]
        public null|string $emailType = null,
    ) {
        $this->status = EmailAuditStatus::Sent;
    }

    public function markAsSent(string $messageId, string $smtpDebugLog): void
    {
        $this->messageId = $messageId;
        $this->smtpDebugLog = $smtpDebugLog;
    }

    public function markAsFailed(string $errorMessage, null|string $smtpDebugLog = null): void
    {
        $this->status = EmailAuditStatus::Failed;
        $this->errorMessage = $errorMessage;
        $this->smtpDebugLog = $smtpDebugLog;
    }

    public function recordBounce(BounceType $type, DateTimeImmutable $at, string $reason): void
    {
        $this->bounceType = $type;
        $this->bouncedAt = $at;
        $this->bounceReason = $reason;
    }
}
