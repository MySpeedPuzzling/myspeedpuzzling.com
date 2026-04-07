<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Message\ConvertCompetitionToSeries;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsMessageHandler]
readonly final class ConvertCompetitionToSeriesHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionRepository $competitionRepository,
        private ClockInterface $clock,
        private SluggerInterface $slugger,
    ) {
    }

    public function __invoke(ConvertCompetitionToSeries $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);

        if ($competition->isOnline === false) {
            throw new \LogicException('Only online competitions can be converted to a recurring series.');
        }

        if ($competition->series !== null) {
            throw new \LogicException('Competition is already part of a series.');
        }

        $now = $this->clock->now();
        $seriesSlug = $this->generateUniqueSeriesSlug($competition->slug ?? $competition->name);

        $series = new CompetitionSeries(
            id: $message->seriesId,
            name: $competition->name,
            slug: $seriesSlug,
            logo: $competition->logo,
            description: $competition->description,
            link: $competition->link,
            isOnline: true,
            shortcut: $competition->shortcut,
            tag: $competition->tag,
            addedByPlayer: $competition->addedByPlayer,
            approvedAt: $competition->approvedAt,
            approvedByPlayer: $competition->approvedByPlayer,
            createdAt: $now,
        );

        foreach ($competition->maintainers as $maintainer) {
            $series->maintainers->add($maintainer);
        }

        $this->entityManager->persist($series);

        $competition->maintainers->clear();
        $competition->series = $series;
        $competition->shortcut = null;
        $competition->logo = null;
        $competition->description = null;
        $competition->link = null;
        $competition->tag = null;
        $competition->approvedAt = null;
        $competition->approvedByPlayer = null;
        $competition->rejectedAt = null;
        $competition->rejectedByPlayer = null;
        $competition->rejectionReason = null;

        $this->entityManager->flush();
    }

    private function generateUniqueSeriesSlug(string $source): string
    {
        $slug = (string) $this->slugger->slug(strtolower($source));

        /** @var int|string $existingCount */
        $existingCount = $this->entityManager->getConnection()
            ->executeQuery(
                'SELECT COUNT(*) FROM competition_series WHERE slug = :slug',
                ['slug' => $slug],
            )
            ->fetchOne();
        $existingCount = (int) $existingCount;

        if ($existingCount > 0) {
            $slug .= '-' . substr(md5(uniqid()), 0, 6);
        }

        return $slug;
    }
}
