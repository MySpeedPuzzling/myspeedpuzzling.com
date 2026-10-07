<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\PageSectionType;

/**
 * Adds take turns per page, so the cap of sections per page (CompetitionPageSection::MAX_PER_PAGE) is counted exactly.
 */
readonly final class AddPageSection implements SerializedByLock
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

    public function lockKey(): string
    {
        return 'page-sections-' . strtolower($this->competitionId ?? (string) $this->seriesId);
    }
}
