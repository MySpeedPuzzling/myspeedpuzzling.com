<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Builds first-try situations on PUZZLE_3000, which no fixture solved (docs/features/first-try-integrity.md).
 * Results are added the normal way; the tag is set in SQL afterwards, the way old duplicates got in.
 */
readonly final class FirstTryScenario
{
    public const string PUZZLE = PuzzleFixture::PUZZLE_3000;
    public const string ADMIN_USER_ID = 'auth0|admin003';

    private MessageBusInterface $messageBus;
    private Connection $database;
    private ObjectManager $entityManager;
    private DateTimeImmutable $now;

    public function __construct(ContainerInterface $container)
    {
        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $this->messageBus = $container->get(MessageBusInterface::class);
        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $this->database = $container->get(Connection::class);
        // The public registry: the EntityManagerInterface service is private
        $this->entityManager = $container->get('doctrine')->getManager();
        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $this->now = $container->get(ClockInterface::class)->now();
    }

    /**
     * @param list<string> $groupPlayers
     */
    public function add(string $userId, array $groupPlayers = [], int $daysAgo = 0, bool $firstTry = false): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: self::PUZZLE,
            competitionId: null,
            time: '05:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: $this->daysAgo($daysAgo),
            firstAttempt: false,
            unboxed: false,
        ));

        if ($firstTry) {
            $this->markFirstTry($timeId->toString());
        }

        return $timeId->toString();
    }

    public function markFirstTry(string $timeId): void
    {
        $this->database->executeStatement('UPDATE puzzle_solving_time SET first_attempt = true WHERE id = :id', ['id' => $timeId]);

        // Loaded entities would not know
        $this->entityManager->clear();
    }

    public function isFirstTry(string $timeId): bool
    {
        return $this->database->fetchOne('SELECT first_attempt FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]) === true;
    }

    public function exists(string $timeId): bool
    {
        return $this->database->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]) !== false;
    }

    public function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            'INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, :at, :source)',
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId, 'at' => $this->now->format('Y-m-d H:i:s'), 'source' => 'self'],
        );
    }

    public function daysAgo(int $days): DateTimeImmutable
    {
        return $this->now->modify("-{$days} days");
    }
}
