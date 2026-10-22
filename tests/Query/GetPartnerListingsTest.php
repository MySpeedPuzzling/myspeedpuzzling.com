<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPartnerListings;
use SpeedPuzzling\Web\Results\PartnerListingRow;
use SpeedPuzzling\Web\Results\PartnerListings;
use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\SkillTier;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPartnerListingsTest extends KernelTestCase
{
    private const string EVENT = PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN;

    private GetPartnerListings $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPartnerListings::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testOpenListingsNewestFirstWithWhatTheEventOffers(): void
    {
        $listings = $this->listingsFor(PlayerFixture::PLAYER_ADMIN, member: true);

        self::assertSame([PartnerFinderFixture::LISTING_FAVORITES_TEAM, PartnerFinderFixture::LISTING_REGULAR_PAIR], $this->ids($listings), 'the closed listing is left out, the newest first');
        self::assertSame([PartnerKind::Pair, PartnerKind::Team], $listings->offer->kinds);
        self::assertSame(4, $listings->offer->teamSize);
        self::assertFalse($listings->registrationManaged);
        self::assertSame(2, $listings->totalOpen);
        self::assertFalse($listings->isCapped());
        self::assertNull($listings->ownRow());

        $regular = $this->row($listings, PartnerFinderFixture::LISTING_REGULAR_PAIR);
        self::assertSame('John Doe', $regular->playerName);
        self::assertSame(['edges', 'sky', PartnerListingRow::customKey('Dark puzzles')], $regular->strengthKeys());
        self::assertSame(['pair'], $regular->kindKeys());
        self::assertSame(['cs', 'en'], $regular->languages);
        self::assertTrue($regular->sent === false && $regular->requestsCount === 0, 'only the owner sees how many got in touch');

        $team = $this->row($listings, PartnerFinderFixture::LISTING_FAVORITES_TEAM);
        self::assertSame(['group'], $team->kindKeys());
        self::assertSame(2, $team->needed);
        self::assertCount(1, $team->coMembers);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $team->coMembers[0]->playerId);

        self::assertSame(['all' => 2, 'pair' => 1, 'team' => 0, 'group' => 1], $listings->kindCounts());
    }

    public function testTheOwnListingComesFirstWithItsRequestsAndTheSenderSeesSent(): void
    {
        $own = $this->listingsFor(PlayerFixture::PLAYER_REGULAR, member: false);

        self::assertSame(PartnerFinderFixture::LISTING_REGULAR_PAIR, $this->ids($own)[0]);
        $row = $own->ownRow();
        self::assertNotNull($row);
        self::assertSame(1, $row->requestsCount);
        self::assertSame(['all' => 1, 'pair' => 0, 'team' => 0, 'group' => 1], $own->kindCounts(), 'the filters count everybody else');

        $sender = $this->listingsFor(PlayerFixture::PLAYER_WITH_STRIPE, member: true);
        self::assertTrue($this->row($sender, PartnerFinderFixture::LISTING_REGULAR_PAIR)->sent);
        self::assertFalse($this->row($sender, PartnerFinderFixture::LISTING_FAVORITES_TEAM)->sent);
    }

    public function testBlocksHideBothWays(): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_REGULAR],
        );

        // The blocker does not see the blocked player's listing ...
        self::assertSame([PartnerFinderFixture::LISTING_FAVORITES_TEAM], $this->ids($this->listingsFor(PlayerFixture::PLAYER_WITH_FAVORITES, member: false)));

        // ... and the blocked player does not see the blocker's
        self::assertSame([PartnerFinderFixture::LISTING_REGULAR_PAIR], $this->ids($this->listingsFor(PlayerFixture::PLAYER_REGULAR, member: false)));

        // A co-member blocking the viewer is left out of the group
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_ADMIN, 'blocked' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        self::assertSame([], $this->row($this->listingsFor(PlayerFixture::PLAYER_WITH_STRIPE, member: true), PartnerFinderFixture::LISTING_FAVORITES_TEAM)->coMembers);
    }

    public function testAPrivatePlayerKeepsNameAndListingButShowsNoNumbersUnlessRevealed(): void
    {
        $listingId = $this->addListing(PlayerFixture::PLAYER_PRIVATE);
        $this->addStats(PlayerFixture::PLAYER_PRIVATE, best500: 2400, solved: 50);

        // PLAYER_REGULAR blocks her (UserBlockFixture) - a stranger who does not: PLAYER_ADMIN
        $stranger = $this->row($this->listingsFor(PlayerFixture::PLAYER_ADMIN, member: true), $listingId);
        self::assertTrue($stranger->private);
        self::assertSame('Jane Smith', $stranger->playerName, 'listing herself shows her name (D5)');
        self::assertNull($stranger->best500Seconds);
        self::assertNull($stranger->solvedTotal);
        self::assertNull($stranger->avatar);

        // PLAYER_WITH_FAVORITES is on her allow list
        $friend = $this->row($this->listingsFor(PlayerFixture::PLAYER_WITH_FAVORITES, member: false), $listingId);
        self::assertFalse($friend->private);
        self::assertSame(2400, $friend->best500Seconds);
        self::assertSame(50, $friend->solvedTotal);

        // And the viewer who blocks her does not see her at all
        self::assertNotContains($listingId, $this->ids($this->listingsFor(PlayerFixture::PLAYER_REGULAR, member: false)));
    }

    public function testTheTierIsForMembersAndNothingRankedForAPlayerWhoOptedOut(): void
    {
        $this->addStats(PlayerFixture::PLAYER_REGULAR, best500: 2700, solved: 120);
        $this->database->executeStatement(
            'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 500, 1.482, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_REGULAR],
        );
        $this->database->executeStatement(
            "INSERT INTO player_skill (id, player_id, pieces_count, skill_score, skill_tier, skill_percentile, confidence, qualifying_puzzles_count, computed_at) VALUES (:id, :player, 500, 1.0, 5, 90.0, 'high', 10, NOW())",
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_REGULAR],
        );
        $this->addStats(PlayerFixture::PLAYER_ADMIN, best500: 2800, solved: 10);

        $member = $this->listingsFor(PlayerFixture::PLAYER_ADMIN, member: true);
        $row = $this->row($member, PartnerFinderFixture::LISTING_REGULAR_PAIR);
        self::assertSame(SkillTier::Expert, $row->skillTier);
        self::assertSame(1482, $row->mspRating);
        self::assertSame(2700, $row->best500Seconds);
        self::assertSame(120, $row->solvedTotal);
        self::assertSame(2800, $member->viewerBest500Seconds);
        self::assertSame('similar', $row->speedComparedTo($member->viewerBest500Seconds));
        self::assertSame('faster', $row->speedComparedTo(3600));
        self::assertSame('slower', $row->speedComparedTo(2000));

        $player = $this->row($this->listingsFor(PlayerFixture::PLAYER_ADMIN, member: false), PartnerFinderFixture::LISTING_REGULAR_PAIR);
        self::assertNull($player->skillTier, 'no tier without membership - not even read');
        self::assertSame(1482, $player->mspRating);

        $this->database->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        $optedOut = $this->row($this->listingsFor(PlayerFixture::PLAYER_ADMIN, member: true), PartnerFinderFixture::LISTING_REGULAR_PAIR);
        self::assertNull($optedOut->skillTier);
        self::assertNull($optedOut->mspRating);
        self::assertSame(2700, $optedOut->best500Seconds, 'best times stay - they are not a ranking');
    }

    public function testRegisteredFollowsTheGoingRuleOnAManagedEvent(): void
    {
        $this->database->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => self::EVENT]);
        $notGoing = $this->addListing(PlayerFixture::PLAYER_WITH_STRIPE);

        $listings = $this->listingsFor(PlayerFixture::PLAYER_ADMIN, member: true);
        self::assertTrue($listings->registrationManaged);
        self::assertTrue($this->row($listings, PartnerFinderFixture::LISTING_REGULAR_PAIR)->registered);
        self::assertFalse($this->row($listings, $notGoing)->registered);

        $this->database->executeStatement('UPDATE competition SET registration_managed = false WHERE id = :id', ['id' => self::EVENT]);
        self::assertNull($this->row($this->listingsFor(PlayerFixture::PLAYER_ADMIN, member: true), PartnerFinderFixture::LISTING_REGULAR_PAIR)->registered);
    }

    public function testNoFinderMeansNull(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        // A past event, a draft, nonsense
        self::assertNull($this->query->forEvent(CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_ADMIN, true));
        self::assertNull($this->query->forEvent('not-a-uuid', PlayerFixture::PLAYER_ADMIN, true));
        self::assertNull($this->query->teaserForEvent(CompetitionFixture::COMPETITION_WJPC_2024));

        $this->database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => self::EVENT]);
        self::assertNull($this->query->forEvent(self::EVENT, PlayerFixture::PLAYER_ADMIN, true));
        self::assertNull($this->query->teaserForEvent(self::EVENT));
    }

    public function testAnEventWithoutListingsStillHasItsFinder(): void
    {
        $this->database->executeStatement('UPDATE partner_listing SET closed_at = NOW() WHERE competition_id = :id', ['id' => self::EVENT]);

        $listings = $this->listingsFor(PlayerFixture::PLAYER_ADMIN, member: true);
        self::assertSame([], $listings->rows);
        self::assertSame(0, $listings->totalOpen);

        $teaser = $this->query->teaserForEvent(self::EVENT);
        self::assertNotNull($teaser);
        self::assertSame(0, $teaser->total);
        self::assertSame([], $teaser->faces);
    }

    public function testTheGuestTeaserCountsWithoutNames(): void
    {
        $teaser = $this->query->teaserForEvent(self::EVENT);

        self::assertNotNull($teaser);
        self::assertSame(2, $teaser->total);
        self::assertSame(1, $teaser->pairs);
        self::assertSame(0, $teaser->teams);
        self::assertSame(1, $teaser->groups);
        self::assertSame(['cz' => 1, 'de' => 1], $teaser->countries);
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR], array_column($teaser->faces, 'playerId'));
        self::assertSame([PartnerKind::Pair, PartnerKind::Team], $teaser->offer->kinds);
    }

    public function testFilterChipsComeFromTheLoadedRows(): void
    {
        $this->addListing(PlayerFixture::PLAYER_WITH_STRIPE, strengths: '["edges"]', custom: '["dark  Puzzles"]');
        $this->addListing(PlayerFixture::PLAYER_PRIVATE, custom: '["Wasgij"]');

        $filters = $this->listingsFor(PlayerFixture::PLAYER_ADMIN, member: true)->strengthFilters();

        // Presets anybody has, in PartnerStrength order; a custom chip of two listings (folded equal), first spelling
        self::assertSame(['edges', 'sky', 'splitting_work', PartnerListingRow::customKey('Dark puzzles')], array_column($filters, 'key'));
        self::assertSame(2, $filters[0]['count']);
        self::assertNotNull($filters[3]['label']);
    }

    public function testTheCapIsReported(): void
    {
        $listings = $this->listingsFor(PlayerFixture::PLAYER_ADMIN, member: true);
        $capped = new PartnerListings($listings->offer, false, null, $listings->rows, 140);

        self::assertTrue($capped->isCapped());
    }

    private function listingsFor(string $viewerId, bool $member): PartnerListings
    {
        TestingViewer::signIn(self::getContainer(), $viewerId);
        $listings = $this->query->forEvent(self::EVENT, $viewerId, $member);
        self::assertNotNull($listings);

        return $listings;
    }

    /**
     * @return list<string>
     */
    private function ids(PartnerListings $listings): array
    {
        return array_map(static fn (PartnerListingRow $row): string => $row->listingId, $listings->rows);
    }

    private function row(PartnerListings $listings, string $listingId): PartnerListingRow
    {
        foreach ($listings->rows as $row) {
            if ($row->listingId === $listingId) {
                return $row;
            }
        }

        self::fail('Listing ' . $listingId . ' is not on the page.');
    }

    private function addListing(string $playerId, string $strengths = '[]', string $custom = '[]'): string
    {
        $id = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            "INSERT INTO partner_listing (id, competition_id, player_id, group_size, looking_for, co_member_ids, strengths, custom_strengths, languages, created_at, updated_at)
             VALUES (:id, :competition, :player, 1, '[\"pair\"]', '[]', :strengths, :custom, '[\"en\"]', NOW(), NOW())",
            ['id' => $id, 'competition' => self::EVENT, 'player' => $playerId, 'strengths' => $strengths, 'custom' => $custom],
        );

        return $id;
    }

    private function addStats(string $playerId, null|int $best500, int $solved): void
    {
        $this->database->executeStatement(
            "INSERT INTO community_player_stats (player_id, solved_total, pieces_total, solves7d, pieces7d, solves30d, solves_prev30d, solves_this_month, pieces_this_month, solves_last_month, pieces_last_month, best500_seconds, best1000_seconds, monthly_solves, favorites_count, computed_at)
             VALUES (:player, :solved, 0, 0, 0, 0, 0, 0, 0, 0, 0, :best500, NULL, '[]', 0, NOW())",
            ['player' => $playerId, 'solved' => $solved, 'best500' => $best500],
        );
    }
}
