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

#[Entity]
#[Index(columns: ['feature_request_id', 'created_at'])]
class FeatureRequestComment
{
    #[Column(type: Types::STRING, length: 200, nullable: true)]
    public null|string $authorName = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public FeatureRequest $featureRequest,
        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Player $author,
        #[Immutable]
        #[Column(type: Types::TEXT)]
        public string $content,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function anonymizeAuthor(string $name): void
    {
        $this->authorName = $name;
        $this->author = null;
    }
}
