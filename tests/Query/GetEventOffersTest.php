<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetEventOffers;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\EventOffersSeller;
use SpeedPuzzling\Web\Results\EventOffersSummary;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Puzzle Swap Fair (.claude/fixtures.md "Marketplace at events"): seller A = PLAYER_WITH_STRIPE has 7 published
 * offers (SELLSWAP_01-07) and brings SELLSWAP_01 + 02; seller B = PLAYER_ADMIN has 5 published (SELLSWAP_08-13 but
 * SELLSWAP_10 is unpublished) and marked nothing; buyer C = PLAYER_REGULAR has none. SELLSWAP_07 is marked for a past
 * edition only - for the fair it is an offer to ask about.
 */
final class GetEventOffersTest extends KernelTestCase
{
    private const string FAIR = MarketplaceEventFixture::COMPETITION_SWAP_FAIR;
    private const string SELLER_A = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string SELLER_B = PlayerFixture::PLAYER_ADMIN;
    private const string BUYER_C = PlayerFixture::PLAYER_REGULAR;

    private GetEventOffers $query;
    private Connection $database;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetEventOffers::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->clock = self::getContainer()->get(ClockInterface::class);
    }

    public function testSwapFairForAGuest(): void
    {
        $summary = $this->query->summary(self::FAIR, null);

        self::assertSame(2, $summary->bringingCount);
        // A's SELLSWAP_03-07 + B's five published offers
        self::assertSame(10, $summary->askCount);
        self::assertSame(12, $summary->totalCount());
        self::assertSame(2, $summary->sellersCount);
        // A brings something, so A comes first although B is going too
        self::assertSame([self::SELLER_A, self::SELLER_B], self::faceIds($summary));
        self::assertSame(0, $summary->viewerBringingCount);
        self::assertSame(0, $summary->viewerPublishedCount);
    }

    public function testFacesCarryWhatTheAvatarNeeds(): void
    {
        $faces = $this->query->summary(self::FAIR, null)->sellers;

        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $faces[0]->playerName);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $faces[0]->displayName());
        self::assertNotSame('', $faces[0]->playerCode);
        self::assertInstanceOf(CountryCode::class, $faces[1]->country);
    }

    public function testViewersOwnLine(): void
    {
        $sellerA = $this->query->summary(self::FAIR, self::SELLER_A);
        self::assertSame(2, $sellerA->viewerBringingCount);
        self::assertSame(7, $sellerA->viewerPublishedCount);

        $sellerB = $this->query->summary(self::FAIR, self::SELLER_B);
        self::assertSame(0, $sellerB->viewerBringingCount);
        self::assertSame(5, $sellerB->viewerPublishedCount);

        $buyerC = $this->query->summary(self::FAIR, self::BUYER_C);
        self::assertSame(0, $buyerC->viewerBringingCount);
        self::assertSame(0, $buyerC->viewerPublishedCount);

        // The viewer's line never changes the counts
        self::assertSame([2, 10, 2], [$buyerC->bringingCount, $buyerC->askCount, $buyerC->sellersCount]);
    }

    public function testUnpublishedOfferIsNeitherBroughtNorAskedAbout(): void
    {
        // B marks the unpublished SELLSWAP_10 for the fair - buyers cannot see it, so it counts nowhere
        $this->markForFair(SellSwapListItemFixture::SELLSWAP_10);

        $summary = $this->query->summary(self::FAIR, self::SELLER_B);

        self::assertSame([2, 10, 2], [$summary->bringingCount, $summary->askCount, $summary->sellersCount]);
        self::assertSame([self::SELLER_A, self::SELLER_B], self::faceIds($summary));
        self::assertSame(0, $summary->viewerBringingCount);
        self::assertSame(5, $summary->viewerPublishedCount);
    }

    public function testSellerWhoLeftTheEventBringsNothing(): void
    {
        // A's rows stay (decision: kept) but A is no longer going
        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = now() WHERE id = :id',
            ['id' => MarketplaceEventFixture::PARTICIPANT_FAIR_SELLER_A],
        );

        $summary = $this->query->summary(self::FAIR, null);

        self::assertSame([0, 5, 1], [$summary->bringingCount, $summary->askCount, $summary->sellersCount]);
        self::assertSame([self::SELLER_B], self::faceIds($summary));
    }

    public function testNobodyWithOffersGoing(): void
    {
        // WJPC 2024: buyer C, PLAYER_WITH_FAVORITES and PLAYER_PRIVATE go - none of them sells
        $summary = $this->query->summary(CompetitionFixture::COMPETITION_WJPC_2024, self::SELLER_A);

        self::assertSame([0, 0, 0], [$summary->bringingCount, $summary->askCount, $summary->sellersCount]);
        self::assertSame(0, $summary->totalCount());
        self::assertSame([], $summary->sellers);
        // The viewer's own offers count wherever they go
        self::assertSame(7, $summary->viewerPublishedCount);
        self::assertSame(0, $summary->viewerBringingCount);
    }

    public function testFacesPreferSellersWhoBringSomethingThenTheMostOffers(): void
    {
        $this->database->executeStatement(
            'DELETE FROM sell_swap_list_item_event WHERE competition_id = :competitionId',
            ['competitionId' => self::FAIR],
        );

        // Nobody brings anything: A has 7 offers, B 5
        self::assertSame([self::SELLER_A, self::SELLER_B], self::faceIds($this->query->summary(self::FAIR, null)));

        // B brings one - B first
        $this->markForFair(SellSwapListItemFixture::SELLSWAP_08);
        $summary = $this->query->summary(self::FAIR, null);

        self::assertSame([self::SELLER_B, self::SELLER_A], self::faceIds($summary));
        self::assertSame([1, 11, 2], [$summary->bringingCount, $summary->askCount, $summary->sellersCount]);
    }

    public function testAtMostFourFaces(): void
    {
        // Three more sellers going (C goes already), one offer each
        $this->goToFair(PlayerFixture::PLAYER_PRIVATE);
        $this->goToFair(PlayerFixture::PLAYER_WITH_FAVORITES);

        foreach ([self::BUYER_C, PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES] as $playerId) {
            $this->database->executeStatement(
                "INSERT INTO sell_swap_list_item (id, player_id, puzzle_id, listing_type, condition, added_at) VALUES (:id, :player, :puzzle, 'sell', 'normal', NOW())",
                ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'puzzle' => PuzzleFixture::PUZZLE_2000],
            );
        }

        $summary = $this->query->summary(self::FAIR, null);

        self::assertSame(5, $summary->sellersCount);
        self::assertSame([2, 13], [$summary->bringingCount, $summary->askCount]);
        self::assertCount(GetEventOffers::SELLER_FACES, $summary->sellers);
        // A brings, B has the most offers of the rest
        self::assertSame([self::SELLER_A, self::SELLER_B], array_slice(self::faceIds($summary), 0, 2));
    }

    public function testFacesLeaveOutAPlayerTheViewerBlocksButTheCountsStay(): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::BUYER_C, 'blocked' => self::SELLER_A],
        );

        TestingViewer::signIn(self::getContainer(), self::BUYER_C);
        $summary = $this->query->summary(self::FAIR, self::BUYER_C);

        self::assertSame([self::SELLER_B], self::faceIds($summary));
        // Aggregates are deliberately unfiltered (docs/features/player-blocklist.md)
        self::assertSame([2, 10, 2], [$summary->bringingCount, $summary->askCount, $summary->sellersCount]);

        TestingViewer::signOut(self::getContainer());
        self::assertSame([self::SELLER_A, self::SELLER_B], self::faceIds($this->query->summary(self::FAIR, null)));
    }

    /**
     * A seller offers in public: the marketplace shows a private seller with name and avatar
     * (docs/features/private-profile-allow-list.md, "left as is, deliberately") - so does the card leading there.
     */
    public function testPrivateSellerIsAFaceLikeOnTheMarketplace(): void
    {
        $this->database->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => self::SELLER_A]);

        $summary = $this->query->summary(self::FAIR, null);

        self::assertSame([self::SELLER_A, self::SELLER_B], self::faceIds($summary));
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $summary->sellers[0]->playerName);
    }

    public function testInvalidIds(): void
    {
        $summary = $this->query->summary('not-a-uuid', null);
        self::assertSame([0, 0, 0, []], [$summary->bringingCount, $summary->askCount, $summary->sellersCount, $summary->sellers]);

        // An invalid viewer id is a guest
        $summary = $this->query->summary(self::FAIR, 'not-a-uuid');
        self::assertSame([2, 10, 0, 0], [$summary->bringingCount, $summary->askCount, $summary->viewerBringingCount, $summary->viewerPublishedCount]);
    }

    /**
     * The event pages decide in PHP, from the row they already loaded, whether to ask at all - the decision must be
     * GetMarketplaceEvents::SQL_QUALIFIES exactly, for every fixture event.
     */
    public function testMarketplaceEventDecisionMatchesTheSqlRule(): void
    {
        $this->assertDecisionMatchesForEveryCompetition();

        // The edges of "not over": its last day, a one-day event without date_to, yesterday, undated
        $this->setDates(self::FAIR, '-2 days', 'today 23:00');
        $this->assertDecisionMatches(self::FAIR, true);

        $this->setDates(self::FAIR, 'today 08:00', null);
        $this->assertDecisionMatches(self::FAIR, true);

        $this->setDates(self::FAIR, '-3 days', '-1 day');
        $this->assertDecisionMatches(self::FAIR, false);

        $this->setDates(self::FAIR, '-1 day', null);
        $this->assertDecisionMatches(self::FAIR, false);

        $this->setDates(self::FAIR, null, '+20 days');
        $this->assertDecisionMatches(self::FAIR, false);

        // An in-person edition of a series that is not approved
        $this->database->executeStatement(
            'UPDATE competition SET is_online = false WHERE id = :id',
            ['id' => CompetitionSeriesFixture::EDITION_UNAPPROVED_1],
        );
        $this->assertDecisionMatches(CompetitionSeriesFixture::EDITION_UNAPPROVED_1, false);

        // Everything in person and upcoming once more - approvals and rejections must still agree
        $this->database->executeStatement("UPDATE competition SET is_online = false, date_from = :from, date_to = NULL", [
            'from' => $this->clock->now()->modify('+5 days')->format('Y-m-d H:i:s'),
        ]);
        $this->assertDecisionMatchesForEveryCompetition();
    }

    public function testForEventPageAsksOnlyForAMarketplaceEvent(): void
    {
        $getCompetitionEvents = self::getContainer()->get(GetCompetitionEvents::class);

        $fair = $this->query->forEventPage($getCompetitionEvents->byId(self::FAIR), true, self::SELLER_A);
        self::assertInstanceOf(EventOffersSummary::class, $fair);
        self::assertSame(2, $fair->viewerBringingCount);

        // Not public - even an in-person upcoming event gets no card
        self::assertNull($this->query->forEventPage($getCompetitionEvents->byId(self::FAIR), false, self::SELLER_A));

        self::assertNull($this->query->forEventPage($getCompetitionEvents->byId(CompetitionSeriesFixture::EDITION_EJJ_69), true, null));
        self::assertNull($this->query->forEventPage($getCompetitionEvents->byId(CompetitionSeriesFixture::EDITION_PAST_ONLY_1), true, null));
    }

    private function assertDecisionMatchesForEveryCompetition(): void
    {
        /** @var list<string> $competitionIds */
        $competitionIds = $this->database->fetchFirstColumn('SELECT id FROM competition ORDER BY id');
        self::assertNotEmpty($competitionIds);

        $qualifying = 0;

        foreach ($competitionIds as $competitionId) {
            $qualifies = self::getContainer()->get(GetMarketplaceEvents::class)->qualifies($competitionId);
            $this->assertDecisionMatches($competitionId, $qualifies);
            $qualifying += $qualifies ? 1 : 0;
        }

        // Both answers occur, or the comparison proves little
        self::assertGreaterThan(0, $qualifying);
        self::assertLessThan(count($competitionIds), $qualifying);
    }

    private function assertDecisionMatches(string $competitionId, bool $expected): void
    {
        $container = self::getContainer();

        self::assertSame($expected, $container->get(GetMarketplaceEvents::class)->qualifies($competitionId), "SQL rule for {$competitionId}");
        self::assertSame(
            $expected,
            $this->query->isMarketplaceEvent(
                $container->get(GetCompetitionEvents::class)->byId($competitionId),
                $container->get(IsCompetitionPubliclyVisible::class)->check($competitionId),
            ),
            "PHP decision for {$competitionId}",
        );
    }

    private function setDates(string $competitionId, null|string $from, null|string $to): void
    {
        $now = $this->clock->now();

        $this->database->executeStatement(
            'UPDATE competition SET date_from = :dateFrom, date_to = :dateTo WHERE id = :id',
            [
                'id' => $competitionId,
                'dateFrom' => $from !== null ? $now->modify($from)->format('Y-m-d H:i:s') : null,
                'dateTo' => $to !== null ? $now->modify($to)->format('Y-m-d H:i:s') : null,
            ],
        );
    }

    private function goToFair(string $playerId): void
    {
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, source, player_id, competition_id, connected_at) VALUES (:id, 'Fair goer', 'cz', 'self_joined', :player, :competition, NOW())",
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'competition' => self::FAIR],
        );
    }

    private function markForFair(string $listItemId): void
    {
        $this->database->executeStatement(
            'INSERT INTO sell_swap_list_item_event (sell_swap_list_item_id, competition_id, added_at) VALUES (:item, :competition, NOW())',
            ['item' => $listItemId, 'competition' => self::FAIR],
        );
    }

    /**
     * @return list<string>
     */
    private static function faceIds(EventOffersSummary $summary): array
    {
        return array_map(static fn (EventOffersSeller $seller): string => $seller->playerId, $summary->sellers);
    }
}
