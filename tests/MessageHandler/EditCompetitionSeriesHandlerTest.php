<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Message\EditCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditCompetitionSeriesHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionSeriesRepository $seriesRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->seriesRepository = self::getContainer()->get(CompetitionSeriesRepository::class);
    }

    public function testRenamingKeepsTheSlug(): void
    {
        $this->messageBus->dispatch($this->edit(name: 'Euro Jigsaw Jam Monthly'));

        $series = $this->series();
        self::assertSame('Euro Jigsaw Jam Monthly', $series->name);
        self::assertSame('euro-jigsaw-jam-series', $series->slug);
    }

    public function testWhoCanEnterAndWhenItHappensAreSaved(): void
    {
        $this->messageBus->dispatch($this->edit(name: 'Euro Jigsaw Jam', eligibility: '18+', schedule: 'Last Sunday of the month'));

        $series = $this->series();
        self::assertSame('18+', $series->eligibility);
        self::assertSame('Last Sunday of the month', $series->schedule);

        $this->messageBus->dispatch($this->edit(name: 'Euro Jigsaw Jam'));

        $series = $this->series();
        self::assertNull($series->eligibility);
        self::assertNull($series->schedule);
    }

    public function testAnExplicitSlugWins(): void
    {
        $this->messageBus->dispatch($this->edit(name: 'Euro Jigsaw Jam', slug: 'ejj'));

        self::assertSame('ejj', $this->series()->slug);
    }

    public function testAnExplicitSlugOfAnotherSeriesIsRefused(): void
    {
        $this->expectException(CompetitionSlugTaken::class);

        $this->messageBus->dispatch($this->edit(name: 'Euro Jigsaw Jam', slug: 'puzzle-meetup-prague'));
    }

    public function testAnInvalidSlugIsRefused(): void
    {
        $this->expectException(InvalidCompetitionSlug::class);

        $this->messageBus->dispatch($this->edit(name: 'Euro Jigsaw Jam', slug: 'Not A Slug'));
    }

    private function edit(string $name, null|string $slug = null, null|string $eligibility = null, null|string $schedule = null): EditCompetitionSeries
    {
        $series = $this->series();

        return new EditCompetitionSeries(
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: $name,
            shortcut: $series->shortcut,
            description: $series->description,
            link: $series->link,
            isOnline: $series->isOnline,
            location: $series->location,
            locationCountryCode: $series->locationCountryCode,
            logo: null,
            maintainerIds: [],
            eligibility: $eligibility,
            schedule: $schedule,
            slug: $slug,
        );
    }

    private function series(): CompetitionSeries
    {
        return $this->seriesRepository->get(CompetitionSeriesFixture::SERIES_EJJ);
    }
}
