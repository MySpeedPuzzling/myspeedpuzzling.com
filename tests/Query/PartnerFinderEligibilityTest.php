<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Query\PartnerFinderEligibility;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PartnerFinderEligibilityTest extends KernelTestCase
{
    private PartnerFinderEligibility $eligibility;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->eligibility = self::getContainer()->get(PartnerFinderEligibility::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheFixtureEventOffersPairsAndTeamsOfFour(): void
    {
        self::assertTrue($this->eligibility->qualifies(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN));

        $offer = $this->eligibility->qualifyingOffer(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN);
        self::assertNotNull($offer);
        self::assertSame([PartnerKind::Pair, PartnerKind::Team], $offer->kinds);
        self::assertSame(4, $offer->teamSize);

        $kinds = $this->eligibility->offeredKinds(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN);
        self::assertSame([PartnerKind::Pair, PartnerKind::Team], $kinds->kinds);
        self::assertSame(4, $kinds->teamSize);
    }

    public function testAnEventWithoutRoundsOffersBoth(): void
    {
        $offer = $this->eligibility->qualifyingOffer(MarketplaceEventFixture::COMPETITION_SWAP_FAIR);

        self::assertNotNull($offer);
        self::assertSame([PartnerKind::Pair, PartnerKind::Team], $offer->kinds);
        self::assertNull($offer->teamSize);
    }

    public function testAPastEventHasNoFinderButItsRoundsStillTell(): void
    {
        // Results Cup: past, solo rounds and pair rounds
        self::assertFalse($this->eligibility->qualifies(OfficialResultsFixture::COMPETITION_RESULTS_CUP));
        self::assertNull($this->eligibility->qualifyingOffer(OfficialResultsFixture::COMPETITION_RESULTS_CUP));
        self::assertSame([PartnerKind::Pair], $this->eligibility->offeredKinds(OfficialResultsFixture::COMPETITION_RESULTS_CUP)->kinds);
    }

    public function testInvalidIdsQualifyForNothing(): void
    {
        self::assertFalse($this->eligibility->qualifies('not-a-uuid'));
        self::assertSame([], $this->eligibility->offeredKinds('not-a-uuid')->kinds);
    }

    public function testThePlayerInAPairEntryIsEntered(): void
    {
        $entered = PartnerFinderEligibility::sqlPlayerEntered(':competitionId', ':playerId');
        $check = fn (string $competitionId, string $playerId): bool => (bool) $this->database
            ->executeQuery("SELECT {$entered}", ['competitionId' => $competitionId, 'playerId' => $playerId])
            ->fetchOne();

        // PLAYER_REGULAR is Hugo of "Edge Hunters" in the Results Cup's pairs
        self::assertTrue($check(OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_REGULAR));
        self::assertFalse($check(OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_WITH_STRIPE));
        // Going, listed, but in no entry
        self::assertFalse($check(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, PlayerFixture::PLAYER_REGULAR));
        self::assertFalse($check(CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_REGULAR));
    }

    /**
     * The PHP pre-check never says "no" to an event the SQL rule takes, and the rule is the marketplace rule plus
     * "offers a pair or a team" - over every fixture event.
     */
    public function testThePreCheckNeverMissesAQualifyingEvent(): void
    {
        $getCompetitionEvents = self::getContainer()->get(GetCompetitionEvents::class);
        $getMarketplaceEvents = self::getContainer()->get(GetMarketplaceEvents::class);

        /** @var list<string> $ids */
        $ids = $this->database->executeQuery('SELECT id FROM competition ORDER BY id')->fetchFirstColumn();
        $qualifying = 0;
        $ruledOutByPreCheck = 0;

        foreach ($ids as $id) {
            /** @var list<string> $categories */
            $categories = $this->database
                ->executeQuery('SELECT category FROM competition_round WHERE competition_id = :id', ['id' => $id])
                ->fetchFirstColumn();
            $roundCategories = array_map(RoundCategory::from(...), $categories);

            $qualifies = $this->eligibility->qualifies($id);
            $mightQualify = $this->eligibility->mightQualify($getCompetitionEvents->byId($id), $roundCategories);

            self::assertSame(
                $getMarketplaceEvents->qualifies($id) && $this->eligibility->offeredKinds($id)->offersAny(),
                $qualifies,
                'SQL_QUALIFIES = marketplace event offering a pair or a team: ' . $id,
            );

            if ($qualifies) {
                $qualifying++;
                self::assertTrue($mightQualify, 'The pre-check rules out a qualifying event: ' . $id);
            }

            if ($mightQualify === false) {
                $ruledOutByPreCheck++;
            }
        }

        self::assertGreaterThan(1, $qualifying);
        self::assertGreaterThan(5, $ruledOutByPreCheck, 'The pre-check spares the query for most events');
    }
}
