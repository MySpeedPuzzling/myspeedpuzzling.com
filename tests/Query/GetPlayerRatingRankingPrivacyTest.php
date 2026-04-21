<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PlayerElo;
use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
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

    public function testPlayerPositionVisibleToPrivateSubject(): void
    {
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $player->changeProfileVisibility(isPrivate: true);
        $this->em->flush();

        // Subject exception: a private player querying their own position must
        // still get a rank (against the pool of public players + themselves).
        $position = $this->query->playerPosition(PlayerFixture::PLAYER_REGULAR, self::PIECES_COUNT);

        // Pool with subject = PLAYER_ADMIN (1500), PLAYER_REGULAR (1400),
        // PLAYER_WITH_FAVORITES (1300), PLAYER_WITH_STRIPE (1200) → rank 2.
        self::assertSame(2, $position);
    }

    public function testPlayerPositionHiddenFromOthersForPrivatePlayer(): void
    {
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $player->changeProfileVisibility(isPrivate: true);
        $this->em->flush();

        // A different player querying the private player's position must not see them.
        // (playerPosition only takes the subject's id, so the subject IS who is
        // queried — but for sanity, the private player's neighbors should keep
        // contiguous ranks because the private one is excluded from their pool.)
        $adminPosition = $this->query->playerPosition(PlayerFixture::PLAYER_ADMIN, self::PIECES_COUNT);
        $favoritesPosition = $this->query->playerPosition(PlayerFixture::PLAYER_WITH_FAVORITES, self::PIECES_COUNT);

        self::assertSame(1, $adminPosition);
        // Without the private filter PLAYER_WITH_FAVORITES would be rank 3 (admin,
        // private, favorites). With the filter PLAYER_REGULAR is dropped from
        // PLAYER_WITH_FAVORITES's pool → rank 2.
        self::assertSame(2, $favoritesPosition);
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
}
