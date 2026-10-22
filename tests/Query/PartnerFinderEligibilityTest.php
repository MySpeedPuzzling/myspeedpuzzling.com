<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Query\PartnerFinderEligibility;
use SpeedPuzzling\Web\Results\PartnerFinderOffer;
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
        $offer = $this->eligibility->qualifyingOffer(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN);
        self::assertNotNull($offer);
        self::assertSame([PartnerKind::Pair, PartnerKind::Team], $offer->kinds);
        self::assertSame(4, $offer->teamSize);
    }

    public function testAnEventWithoutASlugHasNoFinder(): void
    {
        // The finder pages live under the slug (PartnerFinderUrls) - an old event without one never gets a finder
        $this->database->executeStatement('UPDATE competition SET slug = NULL WHERE id = :id', ['id' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN]);

        self::assertNull($this->eligibility->qualifyingOffer(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN));

        $event = self::getContainer()->get(GetCompetitionEvents::class)->byId(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN);
        self::assertNull($event->slug);
        self::assertFalse($this->eligibility->mightQualify($event, [RoundCategory::Duo]));
    }

    public function testAnEventWithoutRoundsOffersBoth(): void
    {
        $offer = $this->eligibility->qualifyingOffer(MarketplaceEventFixture::COMPETITION_SWAP_FAIR);

        self::assertNotNull($offer);
        self::assertSame([PartnerKind::Pair, PartnerKind::Team], $offer->kinds);
        self::assertNull($offer->teamSize);
    }

    public function testAPastEventHasNoFinder(): void
    {
        // Results Cup: past, solo rounds and pair rounds
        self::assertNull($this->eligibility->qualifyingOffer(OfficialResultsFixture::COMPETITION_RESULTS_CUP));
    }

    public function testInvalidIdsQualifyForNothing(): void
    {
        self::assertNull($this->eligibility->qualifyingOffer('not-a-uuid'));
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
     * "has a slug" plus "offers a pair or a team" - over every fixture event.
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

            $event = $getCompetitionEvents->byId($id);
            $qualifies = $this->eligibility->qualifyingOffer($id) !== null;
            $mightQualify = $this->eligibility->mightQualify($event, $roundCategories);

            self::assertSame(
                $getMarketplaceEvents->qualifies($id)
                    && $event->slug !== null
                    && PartnerFinderOffer::fromRoundCategories($roundCategories)->offersAny(),
                $qualifies,
                'SQL_QUALIFIES = marketplace event with a slug offering a pair or a team: ' . $id,
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
