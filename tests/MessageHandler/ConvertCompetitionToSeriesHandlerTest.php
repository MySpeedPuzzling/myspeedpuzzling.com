<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ConvertCompetitionToSeries;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ConvertCompetitionToSeriesHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRepository $competitionRepository;
    private CompetitionSeriesRepository $seriesRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
        $this->seriesRepository = self::getContainer()->get(CompetitionSeriesRepository::class);
    }

    public function testConvertCreatesSeriesWithCorrectFields(): void
    {
        $seriesId = Uuid::uuid7();
        $competitionId = CompetitionFixture::COMPETITION_RECURRING_ONLINE;

        $competition = $this->competitionRepository->get($competitionId);
        $originalName = $competition->name;
        $originalDescription = $competition->description;
        $originalLogo = $competition->logo;
        $originalLink = $competition->link;

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competitionId,
            seriesId: $seriesId,
        ));

        $series = $this->seriesRepository->get($seriesId->toString());

        self::assertSame($originalName, $series->name);
        self::assertSame($originalDescription, $series->description);
        self::assertSame($originalLogo, $series->logo);
        self::assertSame($originalLink, $series->link);
        self::assertTrue($series->isOnline);
        self::assertNotNull($series->slug);
        self::assertNotNull($series->approvedAt);
        self::assertNotNull($series->addedByPlayer);
    }

    public function testCompetitionBecomesEditionOfSeries(): void
    {
        $seriesId = Uuid::uuid7();
        $competitionId = CompetitionFixture::COMPETITION_RECURRING_ONLINE;

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competitionId,
            seriesId: $seriesId,
        ));

        $competition = $this->competitionRepository->get($competitionId);

        self::assertNotNull($competition->series);
        self::assertSame($seriesId->toString(), $competition->series->id->toString());
    }

    public function testCompetitionFieldsAreNulledAfterConversion(): void
    {
        $seriesId = Uuid::uuid7();
        $competitionId = CompetitionFixture::COMPETITION_RECURRING_ONLINE;

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competitionId,
            seriesId: $seriesId,
        ));

        $competition = $this->competitionRepository->get($competitionId);

        self::assertNull($competition->shortcut);
        self::assertNull($competition->logo);
        self::assertNull($competition->description);
        self::assertNull($competition->link);
        self::assertNull($competition->tag);
        self::assertNull($competition->approvedAt);
        self::assertNull($competition->approvedByPlayer);
        self::assertNull($competition->rejectedAt);
        self::assertNull($competition->rejectedByPlayer);
        self::assertNull($competition->rejectionReason);
    }

    public function testMaintainersAreMovedToSeries(): void
    {
        $seriesId = Uuid::uuid7();
        $competitionId = CompetitionFixture::COMPETITION_RECURRING_ONLINE;

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competitionId,
            seriesId: $seriesId,
        ));

        $series = $this->seriesRepository->get($seriesId->toString());
        $competition = $this->competitionRepository->get($competitionId);

        self::assertGreaterThan(0, $series->maintainers->count());
        self::assertCount(0, $competition->maintainers);
    }

    public function testCompetitionSlugIsPreserved(): void
    {
        $seriesId = Uuid::uuid7();
        $competitionId = CompetitionFixture::COMPETITION_RECURRING_ONLINE;

        $competition = $this->competitionRepository->get($competitionId);
        $originalSlug = $competition->slug;

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competitionId,
            seriesId: $seriesId,
        ));

        $competition = $this->competitionRepository->get($competitionId);
        self::assertSame($originalSlug, $competition->slug);
    }

    public function testCannotConvertOfflineCompetition(): void
    {
        $seriesId = Uuid::uuid7();

        try {
            $this->messageBus->dispatch(new ConvertCompetitionToSeries(
                competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
                seriesId: $seriesId,
            ));

            self::fail('Expected LogicException was not thrown');
        } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(\LogicException::class, $previous);
            self::assertStringContainsString('Only online competitions', $previous->getMessage());
        }
    }
}
