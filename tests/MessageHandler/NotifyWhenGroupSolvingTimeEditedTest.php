<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\GroupSolvingTimeEdited;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenGroupSolvingTimeEdited;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NotifyWhenGroupSolvingTimeEditedTest extends KernelTestCase
{
    private NotifyWhenGroupSolvingTimeEdited $handler;
    private EntityManagerInterface $entityManager;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->handler = $container->get(NotifyWhenGroupSolvingTimeEdited::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->database = $container->get(Connection::class);
    }

    public function testEveryOtherMemberIsToldWhoEdited(): void
    {
        // TIME_12: tracked by PLAYER_REGULAR, PLAYER_PRIVATE is the partner and the one editing
        $this->handle(PlayerFixture::PLAYER_PRIVATE, [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE]);

        self::assertSame(1, $this->countFor(PlayerFixture::PLAYER_REGULAR));
        self::assertSame(0, $this->countFor(PlayerFixture::PLAYER_PRIVATE));

        /** @var GetNotifications $getNotifications */
        $getNotifications = self::getContainer()->get(GetNotifications::class);

        $groupEdits = array_values(array_filter(
            $getNotifications->forPlayer(PlayerFixture::PLAYER_REGULAR, 50),
            static fn($notification): bool => $notification->notificationType === NotificationType::GroupSolvingTimeEdited,
        ));

        self::assertCount(1, $groupEdits);
        // The notification is about the editor, not about whoever tracked the time
        self::assertSame(PlayerFixture::PLAYER_PRIVATE, $groupEdits[0]->targetPlayerId);
    }

    public function testMemberRemovedByTheEditIsToldAsWell(): void
    {
        // PLAYER_WITH_FAVORITES is no longer in TIME_12's group, but was before the edit
        $this->handle(PlayerFixture::PLAYER_REGULAR, [
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_PRIVATE,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        ]);

        self::assertSame(1, $this->countFor(PlayerFixture::PLAYER_WITH_FAVORITES));
        self::assertSame(1, $this->countFor(PlayerFixture::PLAYER_PRIVATE));
        self::assertSame(0, $this->countFor(PlayerFixture::PLAYER_REGULAR));
    }

    public function testRepeatedEditsDoNotPileUpWhileUnread(): void
    {
        $members = [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE];

        $this->handle(PlayerFixture::PLAYER_PRIVATE, $members);
        $this->handle(PlayerFixture::PLAYER_PRIVATE, $members);

        self::assertSame(1, $this->countFor(PlayerFixture::PLAYER_REGULAR));
    }

    public function testDeletedTimeIsIgnored(): void
    {
        ($this->handler)(new GroupSolvingTimeEdited(
            Uuid::uuid7(),
            Uuid::fromString(PlayerFixture::PLAYER_PRIVATE),
            [PlayerFixture::PLAYER_REGULAR],
        ));
        $this->entityManager->flush();

        self::assertSame(0, $this->countFor(PlayerFixture::PLAYER_REGULAR));
    }

    /**
     * @param list<string> $membersBeforeEdit
     */
    private function handle(string $editorId, array $membersBeforeEdit): void
    {
        ($this->handler)(new GroupSolvingTimeEdited(
            Uuid::fromString(PuzzleSolvingTimeFixture::TIME_12),
            Uuid::fromString($editorId),
            $membersBeforeEdit,
        ));

        // The doctrine_transaction middleware does this outside of tests
        $this->entityManager->flush();
    }

    private function countFor(string $playerId): int
    {
        /** @var int|string $count */
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM notification WHERE player_id = :playerId AND type = :type',
            ['playerId' => $playerId, 'type' => NotificationType::GroupSolvingTimeEdited->value],
        );

        return (int) $count;
    }
}
