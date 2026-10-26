<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PlayerElo;
use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The MSP Rating board as a "where am I" window (docs/features/xp-levels/ux-polish/design-spec.md §4): one statement for
 * the top rows, the viewer's rows and their position line; ranks shared on equal ratings and always those of the list on
 * screen.
 */
final class GetPlayerRatingRankingWindowTest extends KernelTestCase
{
    private const int PIECES_COUNT = 500;

    private GetPlayerRatingRanking $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var GetPlayerRatingRanking $query */
        $query = $container->get(GetPlayerRatingRanking::class);
        $this->query = $query;

        /** @var PlayerRepository $players */
        $players = $container->get(PlayerRepository::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        // Admin (cz) 1500, Regular (cz) and With favorites (de) tied at 1400, With stripe (gb) 1200 - display ratings
        $ratings = [
            PlayerFixture::PLAYER_ADMIN => 1.5,
            PlayerFixture::PLAYER_REGULAR => 1.4,
            PlayerFixture::PLAYER_WITH_FAVORITES => 1.4,
            PlayerFixture::PLAYER_WITH_STRIPE => 1.2,
        ];

        foreach ($ratings as $playerId => $rating) {
            $em->persist(new PlayerElo(id: Uuid::uuid7(), player: $players->get($playerId), piecesCount: self::PIECES_COUNT, computedAt: TestClock::now(), eloRating: $rating));
        }

        $em->flush();
    }

    public function testEqualRatingsShareTheRankAndTheOrderStaysStable(): void
    {
        $slice = $this->query->window(self::PIECES_COUNT, 100);

        self::assertSame([1, 2, 2, 4], $slice->ranks);
        self::assertSame([1, 2, 2, 4], array_map(static fn ($entry) => $entry->rank, $slice->rows));
        // The tie is ordered by the player id
        self::assertSame(
            [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE],
            array_map(static fn ($entry) => $entry->playerId, $slice->rows),
        );
        self::assertSame(4, $slice->total);
    }

    public function testARankRefersToTheListOnScreen(): void
    {
        // Filtered by Czechia the ranks are the Czech ones - never the world's
        $czech = $this->query->window(self::PIECES_COUNT, 100, viewerPlayerId: PlayerFixture::PLAYER_REGULAR, country: 'cz');

        self::assertSame([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR], array_map(static fn ($entry) => $entry->playerId, $czech->rows));
        self::assertSame([1, 2], $czech->ranks);
        self::assertSame(2, $czech->total);
        self::assertNotNull($czech->viewerPosition);
        self::assertSame(2, $czech->viewerPosition->rank);
        self::assertSame(2, $czech->viewerPosition->total);

        $german = $this->query->window(self::PIECES_COUNT, 100, viewerPlayerId: PlayerFixture::PLAYER_REGULAR, country: 'de');
        self::assertSame([1], $german->ranks);
        // The viewer is not on the German list
        self::assertNull($german->viewerPosition);
        self::assertNull($german->ownPosition);
    }

    public function testTheWindowShowsTheTopAndTheViewersRowsInOneStatement(): void
    {
        // Top 1, then the viewer (4th) with one row above: a gap of one row between them
        $slice = $this->query->window(self::PIECES_COUNT, 1, above: 1, below: 2, viewerPlayerId: PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame([0, 2, 3], $slice->positions);
        self::assertSame(
            [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE],
            array_map(static fn ($entry) => $entry->playerId, $slice->rows),
        );
        self::assertSame(3, $slice->ownPosition);
        self::assertSame(4, $slice->total);

        // The position line: #4 of 4, 200 rating points to #3
        $line = $slice->viewerPosition;
        self::assertNotNull($line);
        self::assertSame(4, $line->rank);
        self::assertSame(4, $line->total);
        self::assertSame(3, $line->targetRank);
        self::assertSame(200, $line->targetGap);
    }

    public function testASearchFindsRowsOfTheListAndKeepsItsRanksAndTheViewersLine(): void
    {
        $slice = $this->query->window(self::PIECES_COUNT, 100, viewerPlayerId: PlayerFixture::PLAYER_REGULAR, searchTerm: PlayerFixture::PLAYER_WITH_STRIPE_NAME);

        self::assertSame([PlayerFixture::PLAYER_WITH_STRIPE], array_map(static fn ($entry) => $entry->playerId, $slice->rows));
        self::assertSame([4], $slice->ranks);
        self::assertSame(1, $slice->total);
        // The viewer does not match: no own row, but their place in the list stays on the line
        self::assertNull($slice->ownPosition);
        self::assertNotNull($slice->viewerPosition);
        self::assertSame(2, $slice->viewerPosition->rank);
        self::assertSame(4, $slice->viewerPosition->total);

        $nothing = $this->query->window(self::PIECES_COUNT, 100, viewerPlayerId: PlayerFixture::PLAYER_REGULAR, searchTerm: 'nobody-has-this-name');
        self::assertSame([], $nothing->rows);
        self::assertSame(0, $nothing->total);
        self::assertNotNull($nothing->viewerPosition);
    }

    public function testThePositionLineAndTheProfileCardSayTheSameRank(): void
    {
        foreach ([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE] as $playerId) {
            $line = $this->query->window(self::PIECES_COUNT, 100, viewerPlayerId: $playerId)->viewerPosition;
            $card = $this->query->allForPlayer($playerId)[self::PIECES_COUNT];

            self::assertNotNull($line);
            self::assertSame($card['rank'], $line->rank, $playerId);
            self::assertSame($card['total'], $line->total, $playerId);
        }
    }

    public function testPrivateAndOptedOutViewersAreNotOnTheBoard(): void
    {
        /** @var PlayerRepository $players */
        $players = self::getContainer()->get(PlayerRepository::class);
        $players->get(PlayerFixture::PLAYER_REGULAR)->changeProfileVisibility(isPrivate: true);
        $players->get(PlayerFixture::PLAYER_WITH_STRIPE)->changeRankingOptedOut(true);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach ([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE] as $playerId) {
            $slice = $this->query->window(self::PIECES_COUNT, 100, viewerPlayerId: $playerId);

            self::assertNull($slice->viewerPosition, $playerId);
            self::assertNull($slice->ownPosition, $playerId);
            self::assertNotContains($playerId, array_map(static fn ($entry) => $entry->playerId, $slice->rows));
        }

        self::assertSame([1, 2], $this->query->window(self::PIECES_COUNT, 100)->ranks);
    }
}
