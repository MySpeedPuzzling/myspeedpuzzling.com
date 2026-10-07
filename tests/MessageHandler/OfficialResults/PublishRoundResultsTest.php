<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\OfficialResults;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Events\OfficialRoundResultsPublished;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Message\UnpublishRoundResults;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenOfficialRoundResultsPublished;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Results\PlayerNotification;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PublishRoundResultsTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheFirstPublishTellsThePlayersOnce(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);

        $round = $this->roundRow(OfficialResultsFixture::ROUND_GROUP_B);
        self::assertNotNull($round['results_published_at']);
        self::assertSame($round['results_published_at'], $round['results_first_published_at']);
        self::assertCount(1, $this->publishedEvents());

        // Taken off and put back: published again, nobody told again
        $this->messageBus->dispatch(new UnpublishRoundResults(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_GROUP_B));
        self::assertNull($this->roundRow(OfficialResultsFixture::ROUND_GROUP_B)['results_published_at']);
        self::assertNotNull($this->roundRow(OfficialResultsFixture::ROUND_GROUP_B)['results_first_published_at']);

        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        self::assertNotNull($this->roundRow(OfficialResultsFixture::ROUND_GROUP_B)['results_published_at']);
        self::assertCount(1, $this->publishedEvents());
    }

    public function testPublishingAPublishedRoundChangesNothing(): void
    {
        $before = $this->roundRow(OfficialResultsFixture::ROUND_GROUP_A);

        $this->publish(OfficialResultsFixture::ROUND_GROUP_A);

        self::assertSame($before, $this->roundRow(OfficialResultsFixture::ROUND_GROUP_A));
        self::assertSame([], $this->publishedEvents());
    }

    public function testARoundOfAnotherCompetitionIsRefused(): void
    {
        $this->expectException(CompetitionRoundNotFound::class);

        $this->messageBus->dispatch(new PublishRoundResults(CompetitionFixture::COMPETITION_WJPC_2024, OfficialResultsFixture::ROUND_GROUP_B));
    }

    public function testPlayersWithAFinishedResultAreNotifiedOnceEvenWhenTheHandlerRunsAgain(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        $event = $this->publishedEvents()[0];

        $this->notify($event);
        $this->notify($event);

        // Gina (PLAYER_PRIVATE) and Hugo (PLAYER_REGULAR) have finished results; Ivan is linked to nobody
        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE], $this->notifiedPlayers(OfficialResultsFixture::ROUND_GROUP_B));

        $notifications = array_values(array_filter(
            self::getContainer()->get(GetNotifications::class)->forPlayer(PlayerFixture::PLAYER_REGULAR, 50),
            static fn (PlayerNotification $notification): bool => $notification->isOfficialResultNotification(),
        ));
        self::assertCount(1, $notifications);
        self::assertSame('Group B', $notifications[0]->roundName);
        self::assertSame('group-b', $notifications[0]->roundSlug);
        self::assertSame('Results Cup', $notifications[0]->roundCompetitionName);
        self::assertSame('results-cup', $notifications[0]->roundCompetitionSlug);
        self::assertNull($notifications[0]->roundSeriesSlug);
    }

    public function testOnlyFinishedResultsOfLinkedPlayersAreNotified(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_PAIRS);

        $this->notify($this->publishedEvents()[0]);

        // Puzzle Sharks (Anna = PLAYER_ADMIN) and Edge Hunters (Gina, Hugo) finished; Corner Pieces did not
        self::assertSame(
            [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN],
            $this->notifiedPlayers(OfficialResultsFixture::ROUND_PAIRS),
        );
    }

    public function testResultsTakenOffBeforeTheNotificationsTellNobody(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        $this->messageBus->dispatch(new UnpublishRoundResults(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_GROUP_B));

        $this->notify($this->publishedEvents()[0]);

        self::assertSame([], $this->notifiedPlayers(OfficialResultsFixture::ROUND_GROUP_B));
    }

    /**
     * The asynchronous handler, as the worker runs it - one transaction
     */
    private function notify(OfficialRoundResultsPublished $event): void
    {
        self::getContainer()->get(NotifyWhenOfficialRoundResultsPublished::class)($event);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function publish(string $roundId): void
    {
        $this->messageBus->dispatch(new PublishRoundResults(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $roundId));
    }

    /**
     * @return list<OfficialRoundResultsPublished>
     */
    private function publishedEvents(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        $events = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof OfficialRoundResultsPublished) {
                $events[] = $message;
            }
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    private function notifiedPlayers(string $roundId): array
    {
        /** @var list<string> $playerIds */
        $playerIds = $this->database->fetchFirstColumn(
            'SELECT player_id FROM notification WHERE target_competition_round_id = :roundId AND type = :type ORDER BY player_id',
            ['roundId' => $roundId, 'type' => NotificationType::OfficialResultPublished->value],
        );

        return $playerIds;
    }

    /**
     * @return array<string, mixed>
     */
    private function roundRow(string $roundId): array
    {
        $row = $this->database->fetchAssociative('SELECT results_published_at, results_first_published_at FROM competition_round WHERE id = :id', ['id' => $roundId]);
        assert(is_array($row));

        return $row;
    }
}
