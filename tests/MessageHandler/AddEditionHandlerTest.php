<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddEditionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRepository $competitionRepository;
    private Connection $database;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->clock = self::getContainer()->get(ClockInterface::class);
    }

    public function testEditionGetsSlugGenerated(): void
    {
        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddEdition(
            competitionId: $competitionId,
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: 'EJJ #70 — June 2026',
            dateFrom: $this->clock->now()->modify('+60 days'),
            dateTo: $this->clock->now()->modify('+60 days'),
            registrationLink: null,
            resultsLink: null,
        ));

        $competition = $this->competitionRepository->get($competitionId->toString());

        self::assertNotNull($competition->slug, 'Edition should have a slug generated');
        self::assertStringContainsString('ejj', $competition->slug);
    }

    public function testEditionDoesNotAutoCreateRound(): void
    {
        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddEdition(
            competitionId: $competitionId,
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: 'Edition No Auto Round',
            dateFrom: $this->clock->now()->modify('+60 days'),
            dateTo: $this->clock->now()->modify('+60 days'),
            registrationLink: null,
            resultsLink: null,
        ));

        /** @var int|string $count */
        $count = $this->database->executeQuery(
            'SELECT COUNT(*) FROM competition_round WHERE competition_id = :cid',
            ['cid' => $competitionId->toString()],
        )->fetchOne();
        $roundCount = (int) $count;

        self::assertSame(0, $roundCount, 'Edition should not auto-create rounds');
    }

    public function testOfflineEditionInheritsLocation(): void
    {
        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddEdition(
            competitionId: $competitionId,
            seriesId: CompetitionSeriesFixture::SERIES_OFFLINE,
            name: 'Offline Edition Test',
            dateFrom: $this->clock->now()->modify('+30 days'),
            dateTo: $this->clock->now()->modify('+31 days'),
            registrationLink: null,
            resultsLink: null,
        ));

        $competition = $this->competitionRepository->get($competitionId->toString());

        self::assertFalse($competition->isOnline);
        self::assertSame('Prague', $competition->location);
        self::assertSame('cz', $competition->locationCountryCode);
    }

    public function testEditionSlugIsUniqueWhenNameCollides(): void
    {
        $competitionId1 = Uuid::uuid7();

        $this->messageBus->dispatch(new AddEdition(
            competitionId: $competitionId1,
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: 'Unique Name Edition',
            dateFrom: $this->clock->now()->modify('+60 days'),
            dateTo: $this->clock->now()->modify('+60 days'),
            registrationLink: null,
            resultsLink: null,
        ));

        $competitionId2 = Uuid::uuid7();

        $this->messageBus->dispatch(new AddEdition(
            competitionId: $competitionId2,
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: 'Unique Name Edition',
            dateFrom: $this->clock->now()->modify('+90 days'),
            dateTo: $this->clock->now()->modify('+90 days'),
            registrationLink: null,
            resultsLink: null,
        ));

        $competition1 = $this->competitionRepository->get($competitionId1->toString());
        $competition2 = $this->competitionRepository->get($competitionId2->toString());

        self::assertNotNull($competition1->slug);
        self::assertNotNull($competition2->slug);
        self::assertNotSame($competition1->slug, $competition2->slug, 'Slugs should be unique');
    }
}
