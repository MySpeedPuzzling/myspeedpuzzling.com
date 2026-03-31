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
use SpeedPuzzling\Web\Value\FeatureRequestStatus;

#[Entity]
#[Index(columns: ['author_id'])]
#[Index(columns: ['vote_count', 'created_at'])]
class FeatureRequest
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::INTEGER)]
    public int $voteCount = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: FeatureRequestStatus::class, options: ['default' => 'open'])]
    public FeatureRequestStatus $status = FeatureRequestStatus::Open;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, length: 500, nullable: true)]
    public null|string $githubUrl = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $adminComment = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $author,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::STRING, length: 255)]
        public string $title,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT)]
        public string $description,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function edit(string $title, string $description): void
    {
        $this->title = $title;
        $this->description = $description;
    }

    public function updateStatus(
        FeatureRequestStatus $status,
        null|string $githubUrl = null,
        null|string $adminComment = null,
    ): void {
        $this->status = $status;
        $this->githubUrl = $githubUrl;
        $this->adminComment = $adminComment;
    }
}
