<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionPageSection;
use SpeedPuzzling\Web\Message\AddPageSection;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Services\PageSectionContentSanitizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPageSectionHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionSeriesRepository $seriesRepository,
        private CompetitionPageSectionRepository $sectionRepository,
        private PageSectionContentSanitizer $sanitizer,
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AddPageSection $message): void
    {
        $competition = $message->competitionId !== null
            ? $this->competitionRepository->get($message->competitionId)
            : null;
        $series = $message->seriesId !== null
            ? $this->seriesRepository->get($message->seriesId)
            : null;

        $section = new CompetitionPageSection(
            id: $message->sectionId,
            competition: $competition,
            series: $series,
            type: $message->type,
            position: $this->nextPosition($message->competitionId, $message->seriesId),
            title: $message->title !== null && trim($message->title) !== '' ? trim($message->title) : null,
            content: $this->sanitizer->sanitize($message->type, $message->content),
            createdAt: $this->clock->now(),
        );

        $this->sectionRepository->save($section);
    }

    private function nextPosition(null|string $competitionId, null|string $seriesId): int
    {
        $query = $competitionId !== null
            ? 'SELECT COALESCE(MAX(position), 0) + 1 FROM competition_page_section WHERE competition_id = :ownerId'
            : 'SELECT COALESCE(MAX(position), 0) + 1 FROM competition_page_section WHERE series_id = :ownerId';

        /** @var int|string $position */
        $position = $this->database->executeQuery($query, [
            'ownerId' => $competitionId ?? $seriesId,
        ])->fetchOne();

        return (int) $position;
    }
}
