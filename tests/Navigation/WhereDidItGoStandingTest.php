<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\NavigationViewer as Viewer;
use SpeedPuzzling\Web\Navigation\NavVisibility;
use SpeedPuzzling\Web\Navigation\WhereDidItGoBuilder;
use SpeedPuzzling\Web\Navigation\WhereDidItGoStanding;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Who gets a "Where did it go?" row, and how (WhereDidItGoBuilder::standing(), LAUNCH-02, LAUNCH review F4): plain where
 * the viewer has the new home; the Members mark only for whoever is no member - a member outside a narrower members'
 * audience (PrivateMember, for a member with a public profile) gets no row instead of a lock; a guest's row for a
 * signed-in home is a guest row; the guests' own rows, the roles and the audiences a viewer is not in are left out.
 */
final class WhereDidItGoStandingTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{NavVisibility, string, null|WhereDidItGoStanding}>
     */
    public static function provideCases(): iterable
    {
        // audience, viewer => standing
        yield 'a private profile\'s row, a member with a public profile' => [NavVisibility::PrivateMember, 'public member', null];
        yield 'a private profile\'s row, a member with a private profile' => [NavVisibility::PrivateMember, 'private member', WhereDidItGoStanding::Open];
        yield 'a private profile\'s row, a player' => [NavVisibility::PrivateMember, 'player', WhereDidItGoStanding::MembersOnly];
        yield 'a private profile\'s row, a guest' => [NavVisibility::PrivateMember, 'guest', WhereDidItGoStanding::MembersOnly];
        yield 'a members\' row, a member' => [NavVisibility::Member, 'public member', WhereDidItGoStanding::Open];
        yield 'a members\' row, a player' => [NavVisibility::Member, 'player', WhereDidItGoStanding::MembersOnly];
        yield 'members or referral, a referral partner' => [NavVisibility::MemberOrReferral, 'referral partner', WhereDidItGoStanding::Open];
        yield 'members or referral, a player' => [NavVisibility::MemberOrReferral, 'player', WhereDidItGoStanding::MembersOnly];
        yield 'a players\' row, a guest' => [NavVisibility::SignedIn, 'guest', WhereDidItGoStanding::Guest];
        yield 'a players\' row, a player' => [NavVisibility::SignedIn, 'player', WhereDidItGoStanding::Open];
        yield 'the guests\' row, a player' => [NavVisibility::GuestOnly, 'player', null];
        yield 'the guests\' row, a guest' => [NavVisibility::GuestOnly, 'guest', WhereDidItGoStanding::Open];
        yield 'an admin row, a member' => [NavVisibility::Admin, 'public member', null];
        yield 'an admin row, a guest' => [NavVisibility::Admin, 'guest', null];
        yield 'an XP page, a player who switched XP off' => [NavVisibility::XpParticipant, 'player', null];
    }

    #[DataProvider('provideCases')]
    public function testTheStanding(NavVisibility $visibility, string $viewer, null|WhereDidItGoStanding $standing): void
    {
        self::assertSame($standing, WhereDidItGoBuilder::standing($visibility, $this->viewer($viewer)));
    }

    private function viewer(string $who): Viewer
    {
        if ($who === 'guest') {
            return Viewer::forGuest(xpVisible: true);
        }

        $profile = self::getContainer()->get(GetPlayerProfile::class)->byId(PlayerFixture::PLAYER_REGULAR);

        return match ($who) {
            'player' => new Viewer($profile, xpVisible: true),
            'referral partner' => new Viewer($profile, xpVisible: true, memberOrReferral: true),
            'public member' => new Viewer($profile, member: true, xpVisible: true, memberOrReferral: true),
            'private member' => new Viewer($profile, member: true, xpVisible: true, privateMember: true, memberOrReferral: true),
            default => throw new \LogicException($who),
        };
    }
}
