<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\CompetitionPageSection;
use SpeedPuzzling\Web\Exceptions\PageSectionNotFound;
use SpeedPuzzling\Web\Message\ReorderPageSections;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Orders only the page's own sections: an id of another page's section (another event's, or the series' from an
 * edition) refuses the whole request before anything moves.
 */
#[AsMessageHandler]
readonly final class ReorderPageSectionsHandler
{
    public function __construct(
        private CompetitionPageSectionRepository $sectionRepository,
    ) {
    }

    /**
     * @throws PageSectionNotFound
     */
    public function __invoke(ReorderPageSections $message): void
    {
        $owner = PageSectionOwner::fromIds($message->competitionId, $message->seriesId);

        /** @var array<string, CompetitionPageSection> $sections */
        $sections = [];

        foreach ($this->sectionRepository->allOf($owner) as $section) {
            $sections[$section->id->toString()] = $section;
        }

        /** @var array<string, CompetitionPageSection> $ordered */
        $ordered = [];

        foreach ($message->sectionIds as $sectionId) {
            $sectionId = strtolower($sectionId);
            $ordered[$sectionId] = $sections[$sectionId] ?? throw new PageSectionNotFound();
        }

        // Sections the request did not name (added meanwhile in another tab) keep their order after the named ones
        $ordered += $sections;

        $position = 1;

        foreach ($ordered as $section) {
            $section->moveTo($position);
            $position++;
        }
    }
}
