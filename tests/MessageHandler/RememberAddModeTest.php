<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Message\AddPuzzleTracking;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\FirstTryScenario;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `player.last_add_mode` (docs/features/navigation README §4.6, ADD-01, plan decision 42): written by the three save
 * handlers only when the message carries a mode - only the add page sends one. The API, multiscan and the collection
 * pages send none and never change it; a refused save leaves it untouched, also in the entity manager.
 */
final class RememberAddModeTest extends KernelTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string USER = PlayerFixture::PLAYER_WITH_STRIPE_USER_ID;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testEachSaveRemembersTheModeItCarries(): void
    {
        self::assertNull($this->lastMode());

        $this->messageBus->dispatch($this->tracking(PuzzleAddMode::Relax));
        self::assertSame('relax', $this->lastMode());

        $this->messageBus->dispatch($this->toCollection(PuzzleAddMode::Collection));
        self::assertSame('collection', $this->lastMode());

        $this->messageBus->dispatch($this->solvingTime(PuzzleAddMode::SpeedPuzzling));
        self::assertSame('speed_puzzling', $this->lastMode());
    }

    public function testSavesWithoutAModeLeaveIt(): void
    {
        $this->setLastMode(PuzzleAddMode::Relax);

        // As the API (CreateSolvingTimeProcessor, AddCollectionItemProcessor), multiscan and the collection pages send them
        $this->messageBus->dispatch($this->solvingTime(null));
        $this->messageBus->dispatch($this->tracking(null));
        $this->messageBus->dispatch($this->toCollection(null));

        self::assertSame('relax', $this->lastMode());
    }

    public function testThePuzzleAlreadyInTheCollectionStillCountsAsTheSave(): void
    {
        $this->messageBus->dispatch($this->toCollection(null));
        self::assertNull($this->lastMode());

        $this->messageBus->dispatch($this->toCollection(PuzzleAddMode::Collection));

        self::assertSame('collection', $this->lastMode());
    }

    public function testARefusedSaveLeavesItAlsoInTheEntityManager(): void
    {
        $scenario = new FirstTryScenario(self::getContainer());
        $scenario->add(self::USER, daysAgo: 10, firstTry: true);
        $this->setLastMode(PuzzleAddMode::Relax);

        try {
            $this->messageBus->dispatch(new AddPuzzleSolvingTime(
                timeId: Uuid::uuid7(),
                userId: self::USER,
                puzzleId: FirstTryScenario::PUZZLE,
                competitionId: null,
                time: '05:00:00',
                comment: null,
                finishedPuzzlesPhoto: null,
                groupPlayers: [],
                finishedAt: null,
                firstAttempt: true,
                unboxed: false,
                rememberAddMode: PuzzleAddMode::SpeedPuzzling,
            ));
            self::fail('A second first try is refused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(FirstTryAlreadyTaken::class, $exception->getPrevious());
        }

        // The rolled-back handler did not touch the managed player: a later flush in the request writes nothing
        $player = $this->entityManager->find(Player::class, self::PLAYER);
        self::assertNotNull($player);
        self::assertSame(PuzzleAddMode::Relax, $player->lastAddMode);
        self::assertSame('relax', $this->lastMode());
    }

    private function solvingTime(null|PuzzleAddMode $remember): AddPuzzleSolvingTime
    {
        return new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: self::USER,
            puzzleId: PuzzleFixture::PUZZLE_1500_02,
            competitionId: null,
            time: '02:10:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
            rememberAddMode: $remember,
        );
    }

    private function tracking(null|PuzzleAddMode $remember): AddPuzzleTracking
    {
        return new AddPuzzleTracking(
            trackingId: Uuid::uuid7(),
            userId: self::USER,
            puzzleId: PuzzleFixture::PUZZLE_1500_02,
            comment: 'remember-add-mode ' . Uuid::uuid7()->toString(),
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            rememberAddMode: $remember,
        );
    }

    private function toCollection(null|PuzzleAddMode $remember): AddPuzzleToCollection
    {
        return new AddPuzzleToCollection(
            playerId: self::PLAYER,
            puzzleId: PuzzleFixture::PUZZLE_1500_02,
            collectionId: null,
            comment: null,
            rememberAddMode: $remember,
        );
    }

    private function setLastMode(PuzzleAddMode $mode): void
    {
        $this->database->executeStatement('UPDATE player SET last_add_mode = :mode WHERE id = :id', ['mode' => $mode->value, 'id' => self::PLAYER]);
        // The next handler loads the player as stored
        $this->entityManager->clear();
    }

    private function lastMode(): null|string
    {
        /** @var false|null|string $mode */
        $mode = $this->database->fetchOne('SELECT last_add_mode FROM player WHERE id = :id', ['id' => self::PLAYER]);
        self::assertNotFalse($mode);

        return $mode;
    }
}
