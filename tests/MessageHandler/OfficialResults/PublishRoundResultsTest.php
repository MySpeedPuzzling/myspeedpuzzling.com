<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\OfficialResults;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\OfficialRoundResultsPublished;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\ApproveCompetition;
use SpeedPuzzling\Web\Message\ApproveCompetitionSeries;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Message\UnpublishRoundResults;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenOfficialRoundResultsPublished;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Repository\OfficialResultNoticeRepository;
use SpeedPuzzling\Web\Results\PlayerNotification;
use SpeedPuzzling\Web\Services\RoundResultChangesParser;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
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

    public function testEveryPublishRunsTheNotificationAndTheFirstPublishIsKept(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);

        $round = $this->roundRow(OfficialResultsFixture::ROUND_GROUP_B);
        self::assertNotNull($round['results_published_at']);
        self::assertSame($round['results_published_at'], $round['results_first_published_at']);
        self::assertCount(1, $this->publishedEvents());

        // Taken off and put back: published again, the first publish kept, the notification runs again
        $this->messageBus->dispatch(new UnpublishRoundResults(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_GROUP_B));
        self::assertNull($this->roundRow(OfficialResultsFixture::ROUND_GROUP_B)['results_published_at']);
        self::assertSame($round['results_first_published_at'], $this->roundRow(OfficialResultsFixture::ROUND_GROUP_B)['results_first_published_at']);

        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        self::assertNotNull($this->roundRow(OfficialResultsFixture::ROUND_GROUP_B)['results_published_at']);
        self::assertCount(2, $this->publishedEvents());
    }

    /**
     * review2-b M3: publish, spot a typo, unpublish before the worker ran, fix it, publish again - the first run sees
     * the results off the page and tells nobody, the second one tells everybody, and no run ever tells anybody twice.
     */
    public function testAQuickUnpublishAndRepublishStillTellsEverybodyOnce(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        $this->messageBus->dispatch(new UnpublishRoundResults(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_GROUP_B));

        $this->notify($this->publishedEvents()[0]);
        self::assertSame([], $this->notifiedPlayers(OfficialResultsFixture::ROUND_GROUP_B));

        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        [$first, $second] = $this->publishedEvents();
        $this->notify($second);
        $this->notify($first);
        $this->notify($second);

        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE], $this->notifiedPlayers(OfficialResultsFixture::ROUND_GROUP_B));
        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE], $this->noticedPlayers(OfficialResultsFixture::ROUND_GROUP_B));
    }

    public function testRepublishedBeforeAnyRunTellsEverybodyOnce(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        $this->messageBus->dispatch(new UnpublishRoundResults(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_GROUP_B));
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);

        foreach ($this->publishedEvents() as $event) {
            $this->notify($event);
        }

        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE], $this->notifiedPlayers(OfficialResultsFixture::ROUND_GROUP_B));
    }

    /**
     * review2-business MAJOR-3 (b): a finished result recorded after the results went public (a referee's phone
     * syncing late) tells that player - and a correction afterwards does not tell them again.
     */
    public function testALateFinishedResultOnAPublishedRoundTellsThatPlayerOnce(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_FINAL);
        $this->notify($this->publishedEvents()[0]);
        self::assertSame([], $this->notifiedPlayers(OfficialResultsFixture::ROUND_FINAL));

        $this->recordFinalResultOfAnna(null, 4000);
        $events = $this->publishedEvents();
        self::assertCount(2, $events);
        $this->notify($events[1]);
        self::assertSame([PlayerFixture::PLAYER_ADMIN], $this->notifiedPlayers(OfficialResultsFixture::ROUND_FINAL));

        // Corrected: the event runs again, nobody is told twice
        $this->recordFinalResultOfAnna(4000, 3950);
        $events = $this->publishedEvents();
        self::assertCount(3, $events);
        $this->notify($events[2]);
        self::assertSame([PlayerFixture::PLAYER_ADMIN], $this->notifiedPlayers(OfficialResultsFixture::ROUND_FINAL));
    }

    /**
     * Review round 3 NIT: a desk batch of many corrections on a published round runs the notification once, not once
     * per result - and a set that records no finished result runs none.
     */
    public function testAChangeSetOfFinishedResultsRunsTheNotificationOnce(): void
    {
        // Group A is published: Filip gets his first result, Dan's unfinished one becomes a time, Ben's is corrected
        $this->messageBus->dispatch(new RecordRoundResults(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: OfficialResultsFixture::ROUND_GROUP_A,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            changes: RoundResultChangesParser::parse([
                ['clientChangeId' => Uuid::uuid4()->toString(), 'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'field' => 'result', 'from' => null, 'to' => ['seconds' => 5100]],
                ['clientChangeId' => Uuid::uuid4()->toString(), 'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_DAN, 'field' => 'result', 'from' => ['piecesPlaced' => 850], 'to' => ['seconds' => 5200]],
                ['clientChangeId' => Uuid::uuid4()->toString(), 'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_BEN, 'field' => 'result', 'from' => ['seconds' => 4200], 'to' => ['seconds' => 4190]],
            ]),
        ));

        $events = $this->publishedEvents();
        self::assertCount(1, $events);
        self::assertSame(OfficialResultsFixture::ROUND_GROUP_A, $events[0]->roundId->toString());
        self::assertSame(5100, $this->database->fetchOne('SELECT result_seconds FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_A_FILIP]));

        // Table numbers and an unfinished result tell nobody anything new
        $this->messageBus->dispatch(new RecordRoundResults(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: OfficialResultsFixture::ROUND_GROUP_A,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            changes: RoundResultChangesParser::parse([
                ['clientChangeId' => Uuid::uuid4()->toString(), 'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'field' => 'table_number', 'from' => null, 'to' => 6],
                ['clientChangeId' => Uuid::uuid4()->toString(), 'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_CARA, 'field' => 'result', 'from' => ['seconds' => 4200], 'to' => ['piecesPlaced' => 990]],
            ]),
        ));

        self::assertCount(1, $this->publishedEvents());
    }

    public function testAResultOnAnUnpublishedRoundRunsNoNotification(): void
    {
        $this->messageBus->dispatch(new RecordRoundResults(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: OfficialResultsFixture::ROUND_GROUP_B,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            changes: RoundResultChangesParser::parse([[
                'clientChangeId' => Uuid::uuid4()->toString(),
                'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_B_IVAN,
                'field' => 'result',
                'from' => ['seconds' => 5000],
                'to' => ['seconds' => 4999],
            ]]),
        ));

        self::assertSame([], $this->publishedEvents());
    }

    /**
     * review2-business MINOR-7: results published on an event nobody can see yet tell nobody (the link would 404) -
     * the players are told when the event is approved.
     */
    public function testAnEventApprovedLaterTellsThePlayersOfItsPublishedRoundsThen(): void
    {
        $this->database->executeStatement('UPDATE competition SET approved_at = NULL WHERE id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);

        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        $this->notify($this->publishedEvents()[0]);
        self::assertSame([], $this->notifiedPlayers(OfficialResultsFixture::ROUND_GROUP_B));

        $this->messageBus->dispatch(new ApproveCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_ADMIN, notifyCreator: false));

        $roundIds = array_map(static fn (OfficialRoundResultsPublished $event): string => $event->roundId->toString(), array_slice($this->publishedEvents(), 1));
        sort($roundIds);
        // Group A (published by the fixture) and Group B
        self::assertSame([OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B], $roundIds);

        foreach ($this->publishedEvents() as $event) {
            $this->notify($event);
        }

        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE], $this->notifiedPlayers(OfficialResultsFixture::ROUND_GROUP_B));
        self::assertSame([PlayerFixture::PLAYER_ADMIN], $this->notifiedPlayers(OfficialResultsFixture::ROUND_GROUP_A));
    }

    public function testAnEditionTellsThePlayersWhenItsSeriesIsApproved(): void
    {
        $this->database->executeStatement('UPDATE competition SET series_id = :series WHERE id = :id', [
            'series' => CompetitionSeriesFixture::SERIES_UNAPPROVED,
            'id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
        ]);

        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        $this->notify($this->publishedEvents()[0]);
        self::assertSame([], $this->notifiedPlayers(OfficialResultsFixture::ROUND_GROUP_B));

        $this->messageBus->dispatch(new ApproveCompetitionSeries(CompetitionSeriesFixture::SERIES_UNAPPROVED, PlayerFixture::PLAYER_ADMIN));

        foreach ($this->publishedEvents() as $event) {
            $this->notify($event);
        }

        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE], $this->notifiedPlayers(OfficialResultsFixture::ROUND_GROUP_B));
    }

    public function testTheMarkerIsClaimedOnceOnly(): void
    {
        $repository = self::getContainer()->get(OfficialResultNoticeRepository::class);
        $now = new \DateTimeImmutable();

        self::assertTrue($repository->claim(PlayerFixture::PLAYER_REGULAR, OfficialResultsFixture::ROUND_GROUP_B, $now));
        self::assertFalse($repository->claim(PlayerFixture::PLAYER_REGULAR, OfficialResultsFixture::ROUND_GROUP_B, $now));
        self::assertTrue($repository->claim(PlayerFixture::PLAYER_REGULAR, OfficialResultsFixture::ROUND_GROUP_A, $now));
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

    private function recordFinalResultOfAnna(null|int $fromSeconds, int $toSeconds): void
    {
        $this->messageBus->dispatch(new RecordRoundResults(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: OfficialResultsFixture::ROUND_FINAL,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            changes: RoundResultChangesParser::parse([[
                'clientChangeId' => Uuid::uuid4()->toString(),
                'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_FINAL_ANNA,
                'field' => 'result',
                'from' => $fromSeconds === null ? null : ['seconds' => $fromSeconds],
                'to' => ['seconds' => $toSeconds],
            ]]),
        ));
    }

    /**
     * @return list<string>
     */
    private function noticedPlayers(string $roundId): array
    {
        /** @var list<string> $playerIds */
        $playerIds = $this->database->fetchFirstColumn(
            'SELECT player_id FROM official_result_notice WHERE round_id = :roundId ORDER BY player_id',
            ['roundId' => $roundId],
        );

        return $playerIds;
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
