<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Value\CollectionPreselection;
use SpeedPuzzling\Web\Value\CollectionVisibility;

final class CollectionPreselectionTest extends TestCase
{
    private const string OWN = '018d0008-0000-0000-0000-000000000001';
    private const string OTHER_OWN = '018d0008-0000-0000-0000-000000000004';

    private const array CHOICES = ['Puzzle Collection' => Collection::SYSTEM_ID, 'Ravensburger' => self::OWN, 'Trefl' => self::OTHER_OWN];

    public function testAMemberWithoutADefaultStartsWithTheSystemCollection(): void
    {
        self::assertSame(Collection::SYSTEM_ID, CollectionPreselection::pick($this->player(true, null), self::CHOICES));
    }

    public function testAMembersDefaultIsPicked(): void
    {
        self::assertSame(self::OWN, CollectionPreselection::pick($this->player(true, self::OWN), self::CHOICES));
        self::assertSame(self::OWN, CollectionPreselection::defaultOf($this->player(true, self::OWN)));
    }

    public function testADefaultThatIsNoChoiceFallsBackToTheSystemCollection(): void
    {
        // Deleted, somebody else's, or the puzzle is in it already
        self::assertSame(Collection::SYSTEM_ID, CollectionPreselection::pick($this->player(true, '018d0008-0000-0000-0000-0000000000ff'), self::CHOICES));
    }

    public function testALapsedMembershipIgnoresTheDefault(): void
    {
        self::assertSame(Collection::SYSTEM_ID, CollectionPreselection::pick($this->player(false, self::OWN), self::CHOICES));
        self::assertSame(Collection::SYSTEM_ID, CollectionPreselection::defaultOf($this->player(false, self::OWN)));
    }

    public function testWithoutTheSystemCollectionTheOnlyChoiceOrNothing(): void
    {
        // The puzzle is in the system collection already, the default holds it too
        self::assertSame(self::OTHER_OWN, CollectionPreselection::pick($this->player(true, self::OWN), ['Trefl' => self::OTHER_OWN]));
        self::assertNull(CollectionPreselection::pick($this->player(true, null), ['Ravensburger' => self::OWN, 'Trefl' => self::OTHER_OWN]));
        self::assertSame(self::OWN, CollectionPreselection::pick($this->player(true, self::OWN), ['Ravensburger' => self::OWN, 'Trefl' => self::OTHER_OWN]));
        self::assertNull(CollectionPreselection::pick($this->player(true, self::OWN), []));
    }

    private function player(bool $member, null|string $defaultCollectionId): PlayerProfile
    {
        return new PlayerProfile(
            playerId: '018d0000-0000-0000-0000-000000000004',
            userId: null,
            playerName: 'Test',
            email: null,
            country: null,
            city: null,
            code: 'test',
            favoritePlayers: [],
            avatar: null,
            bio: null,
            facebook: null,
            instagram: null,
            twitch: null,
            stripeCustomerId: null,
            locale: null,
            membershipEndsAt: null,
            activeMembership: $member,
            puzzleCollectionVisibility: CollectionVisibility::Private,
            unsolvedPuzzlesVisibility: CollectionVisibility::Private,
            wishListVisibility: CollectionVisibility::Private,
            lendBorrowListVisibility: CollectionVisibility::Private,
            solvedPuzzlesVisibility: CollectionVisibility::Private,
            defaultCollectionId: $defaultCollectionId,
        );
    }
}
