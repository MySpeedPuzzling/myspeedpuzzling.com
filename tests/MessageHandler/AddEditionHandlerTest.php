<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

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
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
        $this->clock = self::getContainer()->get(ClockInterface::class);
    }

    public function testEditionGetsSlugGenerated(): void
    {
        $competitionId = Uuid::uuid7();
        $roundId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddEdition(
            competitionId: $competitionId,
            roundId: $roundId,
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: 'EJJ #70 — June 2026',
            startsAt: $this->clock->now()->modify('+60 days'),
            minutesLimit: 120,
            registrationLink: null,
            resultsLink: null,
        ));

        $competition = $this->competitionRepository->get($competitionId->toString());

        self::assertNotNull($competition->slug, 'Edition should have a slug generated');
        self::assertStringContainsString('ejj', $competition->slug);
    }

    public function testEditionSlugIsUniqueWhenNameCollides(): void
    {
        $competitionId1 = Uuid::uuid7();
        $roundId1 = Uuid::uuid7();

        $this->messageBus->dispatch(new AddEdition(
            competitionId: $competitionId1,
            roundId: $roundId1,
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: 'Unique Name Edition',
            startsAt: $this->clock->now()->modify('+60 days'),
            minutesLimit: 120,
            registrationLink: null,
            resultsLink: null,
        ));

        $competitionId2 = Uuid::uuid7();
        $roundId2 = Uuid::uuid7();

        $this->messageBus->dispatch(new AddEdition(
            competitionId: $competitionId2,
            roundId: $roundId2,
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: 'Unique Name Edition',
            startsAt: $this->clock->now()->modify('+90 days'),
            minutesLimit: 120,
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
