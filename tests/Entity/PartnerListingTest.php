<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use SpeedPuzzling\Web\Tests\TestClock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PartnerListing;
use SpeedPuzzling\Web\Entity\PartnerRequest;
use SpeedPuzzling\Web\Exceptions\PartnerListingInvalid;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Repository\PartnerRequestRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\PartnerFinderOffer;
use SpeedPuzzling\Web\Results\PartnerStrengthsNormalized;
use SpeedPuzzling\Web\Value\PartnerGoal;
use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\PartnerListingClosedReason;
use SpeedPuzzling\Web\Value\PartnerStrength;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PartnerListingTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testTheFixturesReadBackTyped(): void
    {
        $repository = self::getContainer()->get(PartnerListingRepository::class);

        $listing = $repository->findOpenFor(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($listing);
        self::assertSame(PartnerFinderFixture::LISTING_REGULAR_PAIR, $listing->id->toString());
        self::assertSame([PartnerKind::Pair], $listing->lookingFor());
        self::assertSame([PartnerStrength::Edges, PartnerStrength::Sky], $listing->strengths());
        self::assertSame(['Dark puzzles'], $listing->customStrengths());
        self::assertSame(['cs', 'en'], $listing->languages());
        self::assertSame(PartnerGoal::TopResult, $listing->goal);
        self::assertTrue($listing->isOpen());

        // The withdrawn one is no open listing
        self::assertNull($repository->findOpenFor(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertSame(PartnerListingClosedReason::Withdrawn, $repository->get(PartnerFinderFixture::LISTING_STRIPE_CLOSED)->closedReason);

        $request = self::getContainer()->get(PartnerRequestRepository::class)->findFor(PartnerFinderFixture::LISTING_REGULAR_PAIR, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($request);
        self::assertSame(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR, $request->id->toString());
        self::assertFalse($request->isReplied());
    }

    public function testAGroupCountsDownThenCloses(): void
    {
        $listing = self::getContainer()->get(PartnerListingRepository::class)->get(PartnerFinderFixture::LISTING_FAVORITES_TEAM);
        $now = new DateTimeImmutable('2030-01-01 10:00');

        $listing->reduceNeeded($now);
        self::assertSame(1, $listing->needed);
        self::assertTrue($listing->isOpen());

        $listing->reduceNeeded($now);
        self::assertFalse($listing->isOpen());
        self::assertSame(PartnerListingClosedReason::Found, $listing->closedReason);

        // Closed stays closed with its first reason
        $listing->close(PartnerListingClosedReason::EventOver, $now->modify('+1 day'));
        self::assertSame(PartnerListingClosedReason::Found, $listing->closedReason);
        self::assertEquals($now, $listing->closedAt);
    }

    public function testRepliedKeepsTheFirstMoment(): void
    {
        $request = self::getContainer()->get(PartnerRequestRepository::class)->get(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR);
        $first = new DateTimeImmutable('2030-01-01 10:00');

        $request->markReplied($first);
        $request->markReplied($first->modify('+1 hour'));

        self::assertEquals($first, $request->repliedAt);
    }

    /**
     * @return iterable<string, array{int, list<PartnerKind>, null|int, list<string>, list<string>, null|string, string}>
     */
    public static function invalidListings(): iterable
    {
        yield 'nothing looked for' => [1, [], null, [], [], null, 'partner_finder.invalid.looking_for'];
        yield 'group of 21' => [21, [PartnerKind::Team], 1, [], [], null, 'partner_finder.invalid.group_size'];
        yield 'on my own with needed' => [1, [PartnerKind::Pair], 1, [], [], null, 'partner_finder.invalid.needed'];
        yield 'group without needed' => [3, [PartnerKind::Team], null, [], [], null, 'partner_finder.invalid.needed'];
        yield 'group over 20 with needed' => [18, [PartnerKind::Team], 3, [], [], null, 'partner_finder.invalid.needed'];
        yield 'group looking for a pair' => [2, [PartnerKind::Pair, PartnerKind::Team], 2, [], [], null, 'partner_finder.invalid.pair_for_group'];
        yield 'more co-members than the group' => [2, [PartnerKind::Team], 2, [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_PRIVATE], [], null, 'partner_finder.invalid.co_members'];
        yield 'co-member not an id' => [2, [PartnerKind::Team], 2, ['john'], [], null, 'partner_finder.invalid.co_members'];
        yield 'five languages' => [1, [PartnerKind::Pair], null, [], ['cs', 'en', 'de', 'fr', 'es'], null, 'partner_finder.invalid.languages'];
        yield 'language not a code' => [1, [PartnerKind::Pair], null, [], ['czech'], null, 'partner_finder.invalid.languages'];
        yield 'note too long' => [1, [PartnerKind::Pair], null, [], [], str_repeat('a', 201), 'partner_finder.invalid.note_too_long'];
    }

    /**
     * @param list<PartnerKind> $lookingFor
     * @param list<string> $coMemberIds
     * @param list<string> $languages
     */
    #[DataProvider('invalidListings')]
    public function testInvalidListingsAreRefused(int $groupSize, array $lookingFor, null|int $needed, array $coMemberIds, array $languages, null|string $note, string $translationKey): void
    {
        try {
            $this->newListing($groupSize, $lookingFor, $needed, $coMemberIds, $languages, $note);
        } catch (PartnerListingInvalid $exception) {
            self::assertSame($translationKey, $exception->translationKey);

            return;
        }

        self::fail('Expected ' . $translationKey);
    }

    public function testValuesAreCleanedUp(): void
    {
        $listing = $this->newListing(
            3,
            [PartnerKind::Team],
            2,
            [strtoupper(PlayerFixture::PLAYER_ADMIN), PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_PRIVATE],
            ['CS', ' en ', 'cs'],
            '   ',
        );

        self::assertTrue($listing->isGroup());
        self::assertSame([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_PRIVATE], $listing->coMemberIds());
        self::assertSame(['cs', 'en'], $listing->languages());
        self::assertNull($listing->note);
    }

    public function testOfferedKindsFollowTheRounds(): void
    {
        self::assertSame([PartnerKind::Pair, PartnerKind::Team], PartnerFinderOffer::fromRoundCategories([])->kinds);
        self::assertSame([], PartnerFinderOffer::fromRoundCategories([RoundCategory::Solo, RoundCategory::Solo])->kinds);
        self::assertSame([PartnerKind::Team], PartnerFinderOffer::fromRoundCategories([RoundCategory::Team, RoundCategory::Solo], 6)->kinds);
        self::assertSame(6, PartnerFinderOffer::fromRoundCategories([RoundCategory::Team], 6)->teamSize);
        self::assertNull(PartnerFinderOffer::fromRoundCategories([RoundCategory::Duo], 6)->teamSize);
    }

    public function testANoteOfARequestIsTrimmed(): void
    {
        $listing = self::getContainer()->get(PartnerListingRepository::class)->get(PartnerFinderFixture::LISTING_REGULAR_PAIR);
        $request = new PartnerRequest(Uuid::uuid7(), $listing, self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_ADMIN), PartnerKind::Pair, '  ', TestClock::now());

        self::assertNull($request->note);
    }

    /**
     * @param list<PartnerKind> $lookingFor
     * @param list<string> $coMemberIds
     * @param list<string> $languages
     */
    private function newListing(int $groupSize, array $lookingFor, null|int $needed, array $coMemberIds, array $languages, null|string $note): PartnerListing
    {
        return new PartnerListing(
            id: Uuid::uuid7(),
            competition: self::getContainer()->get(CompetitionRepository::class)->get(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN),
            player: self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_FAVORITES),
            groupSize: $groupSize,
            lookingFor: $lookingFor,
            needed: $needed,
            coMemberIds: $coMemberIds,
            goal: null,
            strengths: new PartnerStrengthsNormalized(),
            languages: $languages,
            note: $note,
            createdAt: TestClock::now(),
        );
    }
}
