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
use InvalidArgumentException;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\PageSectionType;

/**
 * Organiser-written content block on an event, edition or series page
 * (docs/features/competitions-management/public-page.md).
 *
 * Exactly one of competition/series is set. A series' sections show on every edition's page after the edition's own.
 * The type-specific payload lives in $content, sanitised on every write (PageSectionContentSanitizer).
 */
#[Entity]
class CompetitionPageSection
{
    public const int TITLE_MAX_LENGTH = 255;
    // Sections of one page, visible and hidden together (review 2, A-F9)
    public const int MAX_PER_PAGE = 30;

    /**
     * @param array<string, mixed> $content
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public null|Competition $competition,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public null|CompetitionSeries $series,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: PageSectionType::class)]
        public PageSectionType $type,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public int $position,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: self::TITLE_MAX_LENGTH, nullable: true)]
        public null|string $title,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB)]
        public array $content,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $updatedAt = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(options: ['default' => true])]
        public bool $visible = true,
    ) {
        if (($competition === null) === ($series === null)) {
            throw new InvalidArgumentException('Page section must belong to exactly one of competition or series.');
        }
    }

    /**
     * @param array<string, mixed> $content already sanitised
     */
    public function edit(null|string $title, array $content, DateTimeImmutable $updatedAt): void
    {
        $this->title = $title;
        $this->content = $content;
        $this->updatedAt = $updatedAt;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    public function changeVisibility(bool $visible, DateTimeImmutable $updatedAt): void
    {
        if ($this->visible === $visible) {
            return;
        }

        $this->visible = $visible;
        $this->updatedAt = $updatedAt;
    }
}
