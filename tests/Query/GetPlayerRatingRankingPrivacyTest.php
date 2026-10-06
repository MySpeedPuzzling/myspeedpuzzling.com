<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PlayerElo;
use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerRatingRankingPrivacyTest extends KernelTestCase
{
    private const int PIECES_COUNT = 500;

    private GetPlayerRatingRanking $query;
    private PlayerRepository $playerRepository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var GetPlayerRatingRanking $query */
        $query = $container->get(GetPlayerRatingRanking::class);
        $this->query = $query;

        /** @var PlayerRepository $playerRepository */
        $playerRepository = $container->get(PlayerRepository::class);
        $this->playerRepository = $playerRepository;

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $this->em = $em;

        // Test fixtures don't have enough solves to trigger MspRatingCalculator
        // thresholds. Seed PlayerElo rows directly so the privacy filter can be
        // exercised end-to-end.
        $this->seedRatings([
            PlayerFixture::PLAYER_ADMIN => 1500.0,
            PlayerFixture::PLAYER_REGULAR => 1400.0,
            PlayerFixture::PLAYER_WITH_FAVORITES => 1300.0,
            PlayerFixture::PLAYER_WITH_STRIPE => 1200.0,
        ]);
    }

    public function testPrivatePlayerHiddenFromGlobalRanking(): void
    {
        // Make a ranked public player private — they must vanish from ranking() and totalCount().
        $totalBefore = $this->query->totalCount(self::PIECES_COUNT);
        self::assertSame(4, $totalBefore);

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $player->changeProfileVisibility(isPrivate: true);
        $this->em->flush();

        $totalAfter = $this->query->totalCount(self::PIECES_COUNT);
        self::assertSame(3, $totalAfter);

        $playerIds = array_map(
            static fn ($entry) => $entry->playerId,
            $this->query->ranking(self::PIECES_COUNT),
        );
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
    }

    public function testPrivatePlayerLeavesOthersRanks(): void
    {
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $player->changeProfileVisibility(isPrivate: true);
        $this->em->flush();

        self::assertSame(1, $this->query->allForPlayer(PlayerFixture::PLAYER_ADMIN)[self::PIECES_COUNT]['rank']);
        // Without the private filter PLAYER_WITH_FAVORITES would be rank 3 (admin, private, favorites)
        self::assertSame(
            ['elo_rating' => 1300.0, 'rank' => 2, 'total' => 3],
            $this->query->allForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)[self::PIECES_COUNT],
        );
    }

    public function testOptedOutPeerIsLeftOutOfOthersRankAndTotal(): void
    {
        // Reported 2026-10-06: the profile card said #303 of 1136, the ladder #300 of 1126 - the card still
        // counted the 10 players who opted out of rankings
        $peer = $this->playerRepository->get(PlayerFixture::PLAYER_ADMIN);
        $peer->changeRankingOptedOut(true);
        $this->em->flush();

        self::assertSame(
            ['elo_rating' => 1400.0, 'rank' => 1, 'total' => 3],
            $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR)[self::PIECES_COUNT],
        );
        self::assertSame(
            ['elo_rating' => 1200.0, 'rank' => 3, 'total' => 3],
            $this->query->allForPlayer(PlayerFixture::PLAYER_WITH_STRIPE)[self::PIECES_COUNT],
        );
    }

    public function testEveryRankedPlayerSeesTheLaddersRankAndTotal(): void
    {
        // Above everybody one player of each kind the ladder leaves out (private, opted out), below them a tie:
        // the profile card (allForPlayer) must show each player exactly their ladder row's rank and the ladder's total
        $this->seedRatings([PlayerFixture::PLAYER_PRIVATE => 1600.0]);
        $optedOut = $this->playerRepository->get(PlayerFixture::PLAYER_ADMIN);
        $optedOut->changeRankingOptedOut(true);
        $this->em->flush();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player_elo SET elo_rating = 1300.0 WHERE player_id = :playerId',
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        $ladder = $this->query->ranking(self::PIECES_COUNT);
        $total = $this->query->totalCount(self::PIECES_COUNT);

        self::assertCount(3, $ladder);
        self::assertSame(3, $total);
        // The tie shares a place, the next one skips it - RANK()
        self::assertSame([1, 1, 3], array_map(static fn ($entry) => $entry->rank, $ladder));

        foreach ($ladder as $entry) {
            $card = $this->query->allForPlayer($entry->playerId)[self::PIECES_COUNT];

            self::assertSame($entry->rank, $card['rank'], $entry->playerId);
            self::assertSame($total, $card['total'], $entry->playerId);
        }
    }

    public function testAllForPlayerIncludesPrivateSubjectInOwnRank(): void
    {
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $player->changeProfileVisibility(isPrivate: true);
        $this->em->flush();

        $data = $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertArrayHasKey(self::PIECES_COUNT, $data);
        // Subject's pool: 3 public + self (4 total). One public player faster (admin).
        self::assertSame(2, $data[self::PIECES_COUNT]['rank']);
        self::assertSame(4, $data[self::PIECES_COUNT]['total']);
    }

    public function testAllForPlayerExcludesOtherPrivatePeers(): void
    {
        // Mark a peer (not the subject) private — they must not count in the
        // subject's rank/total.
        $peer = $this->playerRepository->get(PlayerFixture::PLAYER_ADMIN);
        $peer->changeProfileVisibility(isPrivate: true);
        $this->em->flush();

        $data = $this->query->allForPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertArrayHasKey(self::PIECES_COUNT, $data);
        // Pool now: PLAYER_REGULAR (1400, subject), PLAYER_WITH_FAVORITES (1300),
        // PLAYER_WITH_STRIPE (1200). Admin excluded. Subject is fastest → rank 1 of 3.
        self::assertSame(1, $data[self::PIECES_COUNT]['rank']);
        self::assertSame(3, $data[self::PIECES_COUNT]['total']);
    }

    /**
     * @param array<string, float> $ratings player id => rating
     */
    private function seedRatings(array $ratings): void
    {
        foreach ($ratings as $playerId => $rating) {
            $player = $this->playerRepository->get($playerId);
            $this->em->persist(new PlayerElo(
                id: Uuid::uuid7(),
                player: $player,
                piecesCount: self::PIECES_COUNT,
                eloRating: $rating,
            ));
        }

        $this->em->flush();
    }

    public function testBlockedPlayerLeavesTheLadderAndPositionsCloseUp(): void
    {
        // Seeded: PLAYER_ADMIN 1500, PLAYER_REGULAR 1400, PLAYER_WITH_FAVORITES 1300, PLAYER_WITH_STRIPE 1200
        $this->block(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        $entries = $this->query->ranking(self::PIECES_COUNT);
        self::assertSame(
            [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE],
            array_map(static fn ($entry) => $entry->playerId, $entries),
        );
        self::assertSame([1, 2, 3], array_map(static fn ($entry) => $entry->rank, $entries));
        self::assertSame(3, $this->query->totalCount(self::PIECES_COUNT));
        self::assertSame(0, $this->query->totalCount(self::PIECES_COUNT, searchTerm: 'Admin'));
        self::assertSame(
            ['elo_rating' => 1200.0, 'rank' => 3, 'total' => 3],
            $this->query->allForPlayer(PlayerFixture::PLAYER_WITH_STRIPE)[self::PIECES_COUNT],
        );

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame(4, $this->query->totalCount(self::PIECES_COUNT));
        self::assertSame(4, $this->query->allForPlayer(PlayerFixture::PLAYER_WITH_STRIPE)[self::PIECES_COUNT]['rank']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->query->ranking(self::PIECES_COUNT)[0]->playerId);
    }

    public function testCountryFacetDropsACountryOnlyTheBlockedPlayerHolds(): void
    {
        self::assertContains('de', $this->query->distinctCountries(self::PIECES_COUNT));

        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_FAVORITES);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::assertNotContains('de', $this->query->distinctCountries(self::PIECES_COUNT));
    }

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
