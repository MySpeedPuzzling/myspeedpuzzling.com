<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A new round's reveal delay: the default unless the organiser says otherwise, within the bounds.
 */
final class AddCompetitionRoundHandlerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testStoresTheChosenRevealDelay(): void
    {
        $roundId = Uuid::uuid7();
        $this->dispatch($this->round($roundId, 25));

        self::assertSame(25, $this->storedDelay($roundId));
    }

    public function testANewRoundHasTheDefaultDelay(): void
    {
        $roundId = Uuid::uuid7();
        $this->dispatch(new AddCompetitionRound(
            roundId: $roundId,
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            name: 'Default Delay Round',
            minutesLimit: 60,
            startsAt: new DateTimeImmutable('+20 days'),
            timezone: 'Europe/Prague',
            badgeBackgroundColor: null,
            badgeTextColor: null,
        ));

        self::assertSame(RoundPuzzleReveal::DEFAULT_DELAY_MINUTES, $this->storedDelay($roundId));
    }

    public function testARoundInsertedWithoutTheColumnGetsTheDefault(): void
    {
        // An older release inserting a round during a blue-green deploy does not know the column
        $roundId = Uuid::uuid7();
        $this->connection()->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, category) VALUES (:id, :competitionId, 'Old Release Round', 60, NOW(), 'solo')",
            ['id' => $roundId->toString(), 'competitionId' => CompetitionFixture::COMPETITION_WJPC_2024],
        );

        self::assertSame(RoundPuzzleReveal::DEFAULT_DELAY_MINUTES, $this->storedDelay($roundId));
    }

    public function testTheDelayIsBounded(): void
    {
        foreach ([-1, RoundPuzzleReveal::MAX_DELAY_MINUTES + 1] as $invalid) {
            $roundId = Uuid::uuid7();

            try {
                $this->dispatch($this->round($roundId, $invalid));
                self::fail(sprintf('%d minutes is no reveal delay', $invalid));
            } catch (HandlerFailedException $failed) {
                self::assertInstanceOf(\InvalidArgumentException::class, $failed->getPrevious());
            }

            self::assertNull($this->storedDelay($roundId), 'No round created');
        }

        foreach ([0, RoundPuzzleReveal::MAX_DELAY_MINUTES] as $valid) {
            $roundId = Uuid::uuid7();
            $this->dispatch($this->round($roundId, $valid));
            self::assertSame($valid, $this->storedDelay($roundId));
        }
    }

    private function round(UuidInterface $roundId, int $revealDelayMinutes): AddCompetitionRound
    {
        return new AddCompetitionRound(
            roundId: $roundId,
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            name: 'Delay Round ' . $roundId->toString(),
            minutesLimit: 60,
            startsAt: new DateTimeImmutable('+20 days'),
            timezone: 'Europe/Prague',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            revealDelayMinutes: $revealDelayMinutes,
        );
    }

    private function dispatch(AddCompetitionRound $message): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch($message);
    }

    private function storedDelay(UuidInterface $roundId): null|int
    {
        $delay = $this->connection()->fetchOne('SELECT reveal_delay_minutes FROM competition_round WHERE id = :id', ['id' => $roundId->toString()]);

        if ($delay === false) {
            return null;
        }

        self::assertIsInt($delay);

        return $delay;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
