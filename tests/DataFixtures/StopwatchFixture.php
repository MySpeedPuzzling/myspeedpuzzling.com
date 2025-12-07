<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\Stopwatch;

final class StopwatchFixture extends Fixture implements DependentFixtureInterface
{
    public const string STOPWATCH_PAUSED = '018d000d-0000-0000-0000-000000000001';
    public const string STOPWATCH_RUNNING = '018d000d-0000-0000-0000-000000000002';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $player1 = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $puzzle = $this->getReference(PuzzleFixture::PUZZLE_500_01, Puzzle::class);

        // Create a paused stopwatch
        $pausedStopwatch = new Stopwatch(
            id: Uuid::fromString(self::STOPWATCH_PAUSED),
            player: $player1,
            puzzle: $puzzle,
        );
        $pausedStopwatch->start($this->clock->now()->modify('-1 hour'));
        $pausedStopwatch->pause($this->clock->now()->modify('-30 minutes'));
        $manager->persist($pausedStopwatch);
        $this->addReference(self::STOPWATCH_PAUSED, $pausedStopwatch);

        // Create a running stopwatch
        $runningStopwatch = new Stopwatch(
            id: Uuid::fromString(self::STOPWATCH_RUNNING),
            player: $player1,
            puzzle: null,
        );
        $runningStopwatch->start($this->clock->now()->modify('-10 minutes'));
        $manager->persist($runningStopwatch);
        $this->addReference(self::STOPWATCH_RUNNING, $runningStopwatch);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            PuzzleFixture::class,
        ];
    }
}
