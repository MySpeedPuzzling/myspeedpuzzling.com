<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\PageSectionType;

readonly final class AddPageSection
{
    /**
     * @param array<string, mixed> $content
     */
    public function __construct(
        public UuidInterface $sectionId,
        public null|string $competitionId,
        public null|string $seriesId,
        public PageSectionType $type,
        public null|string $title,
        public array $content,
    ) {
    }
}
