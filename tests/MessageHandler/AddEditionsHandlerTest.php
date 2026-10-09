<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Message\AddEditions;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Value\NewEdition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Add several dates" (docs/features/organizations/README.md, D15): up to 24 real editions in one message, each one day.
 */
final class AddEditionsHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRepository $competitionRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
    }

    public function testEveryEditionIsDatedOneDayWithThePlaceOfTheSeries(): void
    {
        $first = Uuid::uuid7();
        $second = Uuid::uuid7();
        $evening = new DateTimeZone('America/New_York');

        $this->messageBus->dispatch(new AddEditions(
            seriesId: OrganizationFixture::SERIES_LANTERN_NIGHTS,
            editions: [
                new NewEdition($first, 'Lantern Night 2 March', new DateTimeImmutable('2030-03-04 19:00', $evening)),
                new NewEdition($second, 'Lantern Night 1 April', new DateTimeImmutable('2030-04-01 23:30', $evening)),
            ],
            eligibility: ' 18+ ',
        ));

        $edition = $this->competitionRepository->get($first->toString());

        self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS, $edition->series?->id->toString());
        self::assertSame('Lantern Night 2 March', $edition->name);
        self::assertSame('lantern-night-2-march', $edition->slug);
        self::assertSame('2030-03-04 00:00:00 UTC', $edition->dateFrom?->format('Y-m-d H:i:s e'));
        self::assertSame('2030-03-04 00:00:00 UTC', $edition->dateTo?->format('Y-m-d H:i:s e'));
        self::assertSame('Riverbend', $edition->location);
        self::assertSame('us', $edition->locationCountryCode);
        self::assertFalse($edition->isOnline);
        self::assertSame('18+', $edition->eligibility);
        self::assertFalse($edition->isDraft);
        // An edition never has its own organization - it is its series'
        self::assertNull($edition->organization);

        self::assertSame('2030-04-01', $this->competitionRepository->get($second->toString())->dateFrom?->format('Y-m-d'));
    }

    public function testDraftsAndNoEligibility(): void
    {
        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddEditions(
            seriesId: OrganizationFixture::SERIES_LANTERN_NIGHTS,
            editions: [new NewEdition($competitionId, 'Lantern Night Draft', new DateTimeImmutable('2030-05-06'))],
            eligibility: '   ',
            isDraft: true,
        ));

        $edition = $this->competitionRepository->get($competitionId->toString());

        self::assertTrue($edition->isDraft);
        self::assertNull($edition->eligibility);
    }

    public function testSlugsAreUniqueInTheSeriesAndInTheBatch(): void
    {
        $ids = [Uuid::uuid7(), Uuid::uuid7(), Uuid::uuid7()];

        // "Lantern Night One" exists in the series already (lantern-night-one)
        $this->messageBus->dispatch(new AddEditions(
            seriesId: OrganizationFixture::SERIES_LANTERN_NIGHTS,
            editions: [
                new NewEdition($ids[0], 'Lantern Night One', new DateTimeImmutable('2030-06-03')),
                new NewEdition($ids[1], 'Lantern Night One', new DateTimeImmutable('2030-07-01')),
                new NewEdition($ids[2], 'Lantern Night Three', new DateTimeImmutable('2030-08-05')),
            ],
        ));

        self::assertSame(
            ['lantern-night-one-2', 'lantern-night-one-3', 'lantern-night-three'],
            array_map(fn (UuidInterface $id): null|string => $this->competitionRepository->get($id->toString())->slug, $ids),
        );
    }

    public function testNoEditionsAreRefused(): void
    {
        $this->assertRefused([]);
    }

    public function testMoreThan24EditionsAreRefused(): void
    {
        $editions = [];

        for ($day = 1; $day <= AddEditions::MAX + 1; $day++) {
            $editions[] = new NewEdition(Uuid::uuid7(), 'Night ' . $day, new DateTimeImmutable('2030-01-01')->modify('+' . $day . ' days'));
        }

        $this->assertRefused($editions);
    }

    /**
     * @param list<NewEdition> $editions
     */
    private function assertRefused(array $editions): void
    {
        try {
            $this->messageBus->dispatch(new AddEditions(OrganizationFixture::SERIES_LANTERN_NIGHTS, $editions));
            self::fail('InvalidArgumentException expected');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }
    }
}
