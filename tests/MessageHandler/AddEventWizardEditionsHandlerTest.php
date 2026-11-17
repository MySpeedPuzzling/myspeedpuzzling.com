<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\AddEditions;
use SpeedPuzzling\Web\Message\AddEventWizardEditions;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Value\NewEdition;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The series path's dates + each date's round (W3): AddEditions and a round per edition it created, in one transaction
 * under the series' lock.
 */
final class AddEventWizardEditionsHandlerTest extends KernelTestCase
{
    private const string SERIES = OrganizationFixture::SERIES_LANTERN_NIGHTS;

    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testEveryNewEditionGetsItsRoundAndASecondSendCreatesNothing(): void
    {
        $first = Uuid::uuid7();
        $second = Uuid::uuid7();
        $message = $this->message([[$first, '2031-03-04'], [$second, '2031-04-01']]);

        self::assertSame(2, $this->send($message));
        self::assertSame(1, $this->roundsOf($first));
        self::assertSame(1, $this->roundsOf($second));

        $round = $this->database()->fetchAssociative('SELECT * FROM competition_round WHERE competition_id = :id', ['id' => $first->toString()]);
        self::assertIsArray($round);
        self::assertSame('Solo', $round['name']);
        self::assertSame(60, $round['minutes_limit']);
        self::assertSame('Europe/Prague', $round['timezone']);

        // The same form again (a double click, a reload): no edition and no round twice
        self::assertSame(0, $this->send($this->message([[$first, '2031-03-04'], [$second, '2031-04-01']])));
        self::assertSame(1, $this->roundsOf($first));
        self::assertSame(1, $this->roundsOf($second));
    }

    public function testADateTheSeriesHasAlreadyGetsNeitherAnEditionNorARound(): void
    {
        $first = Uuid::uuid7();
        $this->send($this->message([[$first, '2031-05-05']]));

        // Another id for the same day - AddEditions skips the day, so no round
        $other = Uuid::uuid7();
        $new = Uuid::uuid7();
        self::assertSame(1, $this->send($this->message([[$other, '2031-05-05'], [$new, '2031-05-12']])));
        self::assertSame(0, $this->roundsOf($other));
        self::assertFalse($this->database()->fetchOne('SELECT 1 FROM competition WHERE id = :id', ['id' => $other->toString()]));
        self::assertSame(1, $this->roundsOf($new));
        self::assertSame(1, $this->roundsOf($first));
    }

    public function testARefusedRoundLeavesNoDateBehind(): void
    {
        $first = Uuid::uuid7();
        $message = $this->message([[$first, '2031-06-02']], teamSize: 99);

        try {
            $this->messageBus->dispatch($message);
            self::fail('A team of 99 is refused');
        } catch (HandlerFailedException) {
        }

        self::assertFalse($this->database()->fetchOne('SELECT 1 FROM competition WHERE id = :id', ['id' => $first->toString()]));
    }

    public function testNoRoundsJustDates(): void
    {
        $first = Uuid::uuid7();
        $message = new AddEventWizardEditions(
            editions: new AddEditions(self::SERIES, [new NewEdition($first, 'Lantern Night no round', new DateTimeImmutable('2031-07-07'))], isDraft: true),
            rounds: [],
        );

        self::assertSame(1, $this->send($message));
        self::assertSame(0, $this->roundsOf($first));
        self::assertTrue($this->database()->fetchOne('SELECT is_draft FROM competition WHERE id = :id', ['id' => $first->toString()]));
    }

    public function testARoundOfAnotherEditionIsRefusedUpFront(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AddEventWizardEditions(
            editions: new AddEditions(self::SERIES, [new NewEdition(Uuid::uuid7(), 'X', new DateTimeImmutable('2031-07-07'))]),
            rounds: [$this->round(Uuid::uuid7(), '2031-07-07')],
        );
    }

    /**
     * @param list<array{UuidInterface, string}> $days
     */
    private function message(array $days, null|int $teamSize = null): AddEventWizardEditions
    {
        $editions = [];
        $rounds = [];

        foreach ($days as [$id, $day]) {
            $editions[] = new NewEdition($id, 'Lantern Night ' . $day, new DateTimeImmutable($day));
            $rounds[] = $this->round($id, $day, $teamSize);
        }

        return new AddEventWizardEditions(new AddEditions(self::SERIES, $editions), $rounds);
    }

    private function round(UuidInterface $competitionId, string $day, null|int $teamSize = null): AddCompetitionRound
    {
        return new AddCompetitionRound(
            roundId: Uuid::uuid7(),
            competitionId: $competitionId->toString(),
            name: $teamSize !== null ? 'Teams' : 'Solo',
            minutesLimit: 60,
            startsAt: new DateTimeImmutable($day . ' 18:00', new DateTimeZone('UTC')),
            timezone: 'Europe/Prague',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: $teamSize !== null ? RoundCategory::Team : RoundCategory::Solo,
            teamSize: $teamSize,
        );
    }

    private function send(AddEventWizardEditions $message): int
    {
        $result = $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
        self::assertIsInt($result);

        return $result;
    }

    private function roundsOf(UuidInterface $competitionId): int
    {
        $count = $this->database()->fetchOne('SELECT COUNT(*) FROM competition_round WHERE competition_id = :id', ['id' => $competitionId->toString()]);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
