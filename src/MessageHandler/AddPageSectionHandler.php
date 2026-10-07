<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionPageSection;
use SpeedPuzzling\Web\Exceptions\PageSectionLimitReached;
use SpeedPuzzling\Web\Exceptions\PageSectionTypeNotAvailable;
use SpeedPuzzling\Web\Message\AddPageSection;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Services\PageSectionContentSanitizer;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A new section goes last on its page.
 */
#[AsMessageHandler]
readonly final class AddPageSectionHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionSeriesRepository $seriesRepository,
        private CompetitionPageSectionRepository $sectionRepository,
        private PageSectionContentSanitizer $sanitizer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PageSectionTypeNotAvailable
     * @throws PageSectionLimitReached
     */
    public function __invoke(AddPageSection $message): void
    {
        $owner = PageSectionOwner::fromIds($message->competitionId, $message->seriesId);
        $competition = $owner->competitionId !== null ? $this->competitionRepository->get($owner->competitionId) : null;
        $series = $owner->seriesId !== null ? $this->seriesRepository->get($owner->seriesId) : null;
        $isOnline = $competition !== null ? $competition->isOnline : ($series !== null && $series->isOnline);

        if ($message->type->isAvailableFor($isOnline) === false) {
            throw new PageSectionTypeNotAvailable();
        }

        $existingSections = $this->sectionRepository->allOf($owner);

        // Counted under the page's lock (AddPageSection is SerializedByLock) - never one over the cap
        if (count($existingSections) >= CompetitionPageSection::MAX_PER_PAGE) {
            throw new PageSectionLimitReached();
        }

        $lastPosition = 0;

        foreach ($existingSections as $existing) {
            $lastPosition = max($lastPosition, $existing->position);
        }

        $this->sectionRepository->save(new CompetitionPageSection(
            id: $message->sectionId,
            competition: $competition,
            series: $series,
            type: $message->type,
            position: $lastPosition + 1,
            title: self::cleanTitle($message->title),
            content: $this->sanitizer->sanitize($message->type, $message->content, $owner),
            createdAt: $this->clock->now(),
        ));
    }

    public static function cleanTitle(null|string $title): null|string
    {
        $title = trim((string) $title);

        return $title === '' ? null : mb_substr($title, 0, CompetitionPageSection::TITLE_MAX_LENGTH);
    }
}
