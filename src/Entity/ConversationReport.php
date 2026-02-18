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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\ReportStatus;

#[Entity]
#[Index(columns: ['status'])]
#[Index(columns: ['reported_at'])]
class ConversationReport
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $resolvedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: true)]
    public null|Player $resolvedBy = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $adminNote = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Conversation $conversation,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $reporter,
        #[Immutable]
        #[Column(type: Types::TEXT)]
        public string $reason,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::STRING, enumType: ReportStatus::class)]
        public ReportStatus $status,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $reportedAt,
    ) {
    }

    public function resolve(Player $admin, ReportStatus $status, null|string $note): void
    {
        $this->status = $status;
        $this->resolvedAt = new DateTimeImmutable();
        $this->resolvedBy = $admin;
        $this->adminNote = $note;
    }

    public function dismiss(Player $admin, null|string $note): void
    {
        $this->status = ReportStatus::Dismissed;
        $this->resolvedAt = new DateTimeImmutable();
        $this->resolvedBy = $admin;
        $this->adminNote = $note;
    }
}
