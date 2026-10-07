<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\BackfillRoundTimezones;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class BackfillRoundTimezonesHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testDryRunListsTheRoundsAndSavesNothing(): void
    {
        $result = $this->runBackfill(dryRun: true);

        self::assertNotSame([], $result['changes']);
        self::assertNull($this->storedTimezone(CompetitionSeriesFixture::ROUND_EJJ_69));
    }

    public function testSavesTheZoneEveryRoundIsReadInAndRunningAgainChangesNothing(): void
    {
        $withoutZone = $this->roundsWithoutZone();
        self::assertGreaterThan(0, $withoutZone);

        $result = $this->runBackfill(dryRun: false);

        self::assertCount($withoutZone, $result['changes']);
        self::assertSame(0, $this->roundsWithoutZone());
        // An online series without a country: the fallback, still read as assumed (named without a place)
        self::assertSame('Europe/Prague', $this->storedTimezone(CompetitionSeriesFixture::ROUND_EJJ_69));

        self::assertSame([], $this->runBackfill(dryRun: false)['changes']);
    }

    public function testListsAStartThatLooksMovedByTheOldBug(): void
    {
        // Midnight UTC = 01:00 or 02:00 in Prague - nobody's round starts in the middle of the night
        $this->database->executeStatement(
            "UPDATE competition_round SET starts_at = (date_trunc('day', starts_at) + interval '0 hours') WHERE id = :id",
            ['id' => CompetitionSeriesFixture::ROUND_EJJ_69],
        );

        $suspects = $this->runBackfill(dryRun: true)['suspects'];

        self::assertCount(1, array_filter($suspects, static fn (string $line): bool => str_contains($line, CompetitionSeriesFixture::ROUND_EJJ_69)));
    }

    /**
     * @return array{changes: list<string>, suspects: list<string>}
     */
    private function runBackfill(bool $dryRun): array
    {
        $envelope = $this->messageBus->dispatch(new BackfillRoundTimezones(dryRun: $dryRun));

        /** @var array{changes: list<string>, suspects: list<string>} $result */
        $result = $envelope->last(HandledStamp::class)?->getResult();

        return $result;
    }

    private function roundsWithoutZone(): int
    {
        /** @var int|string $count */
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM competition_round WHERE timezone IS NULL');

        return (int) $count;
    }

    private function storedTimezone(string $roundId): null|string
    {
        /** @var false|null|string $timezone */
        $timezone = $this->database->fetchOne('SELECT timezone FROM competition_round WHERE id = :id', ['id' => $roundId]);

        return $timezone === false ? null : $timezone;
    }
}
