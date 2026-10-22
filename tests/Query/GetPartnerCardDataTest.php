<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPartnerCardData;
use SpeedPuzzling\Web\Results\PartnerCardData;
use SpeedPuzzling\Web\Results\PartnerCardFace;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\HintType;
use SpeedPuzzling\Web\Value\PartnerKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Partner Finder Open (.claude/fixtures.md "Partner finder"): PLAYER_REGULAR's open pair listing (-4 days, one request
 * from PLAYER_WITH_STRIPE), PLAYER_WITH_FAVORITES' open group of 2 looking for a team (-3 days), PLAYER_WITH_STRIPE's
 * closed one. PLAYER_REGULAR blocks PLAYER_PRIVATE.
 */
final class GetPartnerCardDataTest extends KernelTestCase
{
    private const string EVENT = PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN;

    private GetPartnerCardData $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPartnerCardData::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAViewerWithoutAListingSeesEveryOpenListingNewestFirst(): void
    {
        $data = $this->forViewer(PlayerFixture::PLAYER_ADMIN);

        self::assertSame(2, $data->listingCount, 'the closed listing does not count');
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR], self::faceIds($data));
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES_NAME, $data->faces[0]->playerName);
        self::assertFalse($data->isListed());
        self::assertSame([], $data->ownLookingFor);
        self::assertSame(0, $data->ownRequestsCount);
        self::assertFalse($data->viewerEntered);
        self::assertFalse($data->cardDismissed);
    }

    public function testTheViewersOwnListingWithItsRequestsAndNoFaceOfTheirOwn(): void
    {
        $data = $this->forViewer(PlayerFixture::PLAYER_REGULAR);

        self::assertSame(PartnerFinderFixture::LISTING_REGULAR_PAIR, $data->ownListingId);
        self::assertSame([PartnerKind::Pair], $data->ownLookingFor);
        self::assertSame(1, $data->ownGroupSize);
        self::assertNull($data->ownNeeded);
        self::assertFalse($data->isOwnGroup());
        self::assertSame(1, $data->ownRequestsCount);
        self::assertSame(2, $data->listingCount, 'the count is everybody, the viewer included');
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], self::faceIds($data));
    }

    public function testAGroupListing(): void
    {
        $data = $this->forViewer(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame(PartnerFinderFixture::LISTING_FAVORITES_TEAM, $data->ownListingId);
        self::assertSame([PartnerKind::Team], $data->ownLookingFor);
        self::assertTrue($data->isOwnGroup());
        self::assertSame(2, $data->ownGroupSize);
        self::assertSame(2, $data->ownNeeded);
        self::assertSame(0, $data->ownRequestsCount);
    }

    public function testAClosedListingIsNotTheViewersOwn(): void
    {
        $data = $this->forViewer(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertFalse($data->isListed());
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR], self::faceIds($data));
    }

    public function testAPlayerTheViewerBlocksIsNeitherAFaceNorCountedAndTheirRequestIsNotCounted(): void
    {
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_FAVORITES);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        $data = $this->forViewer(PlayerFixture::PLAYER_ADMIN);

        self::assertSame([PlayerFixture::PLAYER_REGULAR], self::faceIds($data));
        // The count agrees with the finder page's rows, which hide listers both ways
        self::assertSame(1, $data->listingCount);

        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::assertSame(0, $this->forViewer(PlayerFixture::PLAYER_REGULAR)->ownRequestsCount, 'a request of a hidden sender');

        TestingViewer::signOut(self::getContainer());
    }

    public function testAListerWhoBlocksTheViewerIsNeitherAFaceNorCounted(): void
    {
        // Without this the blocked viewer would see the blocker's face and a count one higher than the finder page's rows
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_ADMIN);

        $data = $this->forViewer(PlayerFixture::PLAYER_ADMIN);

        self::assertSame([PlayerFixture::PLAYER_REGULAR], self::faceIds($data));
        self::assertSame(1, $data->listingCount);

        // Everybody else still sees them
        $other = $this->forViewer(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR], self::faceIds($other));
        self::assertSame(2, $other->listingCount);
    }

    public function testAPrivatePlayerHiddenFromTheViewerHasNoFaceUnlessTheAllowListRevealsThem(): void
    {
        $this->openListing(PlayerFixture::PLAYER_PRIVATE, '-1 day');

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        $data = $this->forViewer(PlayerFixture::PLAYER_ADMIN);

        self::assertSame(3, $data->listingCount);
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR], self::faceIds($data));

        $this->database->executeStatement(
            'INSERT INTO private_profile_viewer (id, owner_id, viewer_id, added_at) VALUES (:id, :owner, :viewer, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'owner' => PlayerFixture::PLAYER_PRIVATE, 'viewer' => PlayerFixture::PLAYER_ADMIN],
        );
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        self::assertSame(
            [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR],
            self::faceIds($this->forViewer(PlayerFixture::PLAYER_ADMIN)),
        );

        TestingViewer::signOut(self::getContainer());
    }

    public function testAtMostFourFaces(): void
    {
        $this->openListing(PlayerFixture::PLAYER_PRIVATE, '-2 hours');
        $this->openListing(PlayerFixture::PLAYER_WITH_STRIPE, '-1 hour');
        $this->openListing(PlayerFixture::PLAYER_ADMIN, '-30 minutes');

        // Five public puzzlers listed, the viewer none of them
        $this->database->executeStatement('UPDATE player SET is_private = false WHERE id = :id', ['id' => PlayerFixture::PLAYER_PRIVATE]);
        $data = $this->forViewer(Uuid::uuid7()->toString());

        self::assertSame(5, $data->listingCount);
        self::assertSame(
            [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES],
            self::faceIds($data),
        );
        self::assertCount(GetPartnerCardData::FACES, $data->faces);
    }

    public function testTheClosedCard(): void
    {
        $this->database->executeStatement(
            'INSERT INTO dismissed_hint (id, player_id, type, dismissed_at) VALUES (:id, :player, :type, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_ADMIN, 'type' => HintType::PartnerFinderCard->value],
        );

        self::assertTrue($this->forViewer(PlayerFixture::PLAYER_ADMIN)->cardDismissed);
        self::assertFalse($this->forViewer(PlayerFixture::PLAYER_WITH_STRIPE)->cardDismissed);
    }

    public function testAPlayerInAPairOrTeamEntryOfTheEvent(): void
    {
        // The Results Cup (pairs rounds, PLAYER_REGULAR = Hugo of "Edge Hunters") moved into the future
        $this->database->executeStatement(
            "UPDATE competition SET date_from = NOW() + INTERVAL '20 days', date_to = NOW() + INTERVAL '20 days' WHERE id = :id",
            ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
        );

        $entered = $this->query->forViewer(OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($entered);
        self::assertTrue($entered->viewerEntered);

        self::assertFalse($this->forViewer(PlayerFixture::PLAYER_REGULAR)->viewerEntered);
    }

    public function testNoFinderNoData(): void
    {
        self::assertNull($this->query->forViewer(OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_REGULAR), 'over');
        self::assertNull($this->query->forViewer('not-a-uuid', PlayerFixture::PLAYER_REGULAR));
        self::assertNull($this->query->forViewer(self::EVENT, 'not-a-uuid'));

        $this->database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => self::EVENT]);
        self::assertNull($this->query->forViewer(self::EVENT, PlayerFixture::PLAYER_REGULAR), 'a draft');

        $this->database->executeStatement('UPDATE competition SET is_draft = false WHERE id = :id', ['id' => self::EVENT]);
        $this->database->executeStatement("UPDATE competition_round SET category = 'solo' WHERE competition_id = :id", ['id' => self::EVENT]);
        self::assertNull($this->query->forViewer(self::EVENT, PlayerFixture::PLAYER_REGULAR), 'solo rounds only');
    }

    private function forViewer(string $playerId): PartnerCardData
    {
        $data = $this->query->forViewer(self::EVENT, $playerId);
        self::assertNotNull($data);

        return $data;
    }

    private function openListing(string $playerId, string $age): void
    {
        $this->database->executeStatement(
            "INSERT INTO partner_listing (id, competition_id, player_id, group_size, looking_for, created_at, updated_at)
             VALUES (:id, :competition, :player, 1, '[\"pair\"]', NOW() + CAST(:age AS interval), NOW())",
            ['id' => Uuid::uuid7()->toString(), 'competition' => self::EVENT, 'player' => $playerId, 'age' => $age],
        );
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }

    /**
     * @return list<string>
     */
    private static function faceIds(PartnerCardData $data): array
    {
        return array_map(static fn (PartnerCardFace $face): string => $face->playerId, $data->faces);
    }
}
