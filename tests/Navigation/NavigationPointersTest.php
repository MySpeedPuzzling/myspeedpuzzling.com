<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\NavigationLaunch;
use SpeedPuzzling\Web\Navigation\NavigationPointers;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Twig\Environment;

/**
 * The navigation pointers' one rule (INB-05; LAUNCH-03's launch pointer): shown iff the launch date is set, now is
 * inside [date, date + 30 days), the account was created before the date, the pointer is not dismissed and - for the
 * Inbox pointer - the viewer follows somebody. Never for a guest, nothing while the date is null.
 */
final class NavigationPointersTest extends KernelTestCase
{
    private const string LAUNCH_DAY = '2026-11-02';
    private const string INSIDE = '2026-11-10 12:00:00';
    private const string OLD_ACCOUNT = '2025-05-01 10:00:00';

    public function testNothingWhileTheDateIsNull(): void
    {
        $pointers = new NavigationPointers(new NavigationLaunch(new MockClock(self::INSIDE, 'Europe/Prague'), null));

        self::assertFalse($pointers->isActive($this->viewer(), HintType::NavigationInboxPointer));
        // Not even for an account without a registration date, which counts as old once the date is set
        self::assertFalse($pointers->isActive($this->viewer(registeredAt: null), HintType::NavigationInboxPointer));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideMoments(): iterable
    {
        // moment (Prague) => the Inbox pointer shows for an old account that follows somebody
        yield 'the evening before' => ['2026-11-01 23:59:59', false];
        yield 'at midnight of the launch day' => ['2026-11-02 00:00:00', true];
        yield 'inside' => [self::INSIDE, true];
        yield 'the last second of the 30 days' => ['2026-12-01 23:59:59', true];
        yield '+30 days: gone' => ['2026-12-02 00:00:00', false];
        yield 'months later' => ['2027-03-01 08:00:00', false];
    }

    #[DataProvider('provideMoments')]
    public function testThe30Days(string $now, bool $shown): void
    {
        self::assertSame($shown, $this->pointers($now)->isActive($this->viewer(), HintType::NavigationInboxPointer));
    }

    public function testDismissedIsGoneForGood(): void
    {
        $pointers = $this->pointers(self::INSIDE);

        self::assertTrue($pointers->isActive($this->viewer(dismissed: []), HintType::NavigationInboxPointer));
        self::assertFalse($pointers->isActive($this->viewer(dismissed: [HintType::NavigationInboxPointer]), HintType::NavigationInboxPointer));
    }

    public function testOnlyAccountsCreatedBeforeTheDate(): void
    {
        $pointers = $this->pointers(self::INSIDE);

        // Registered the evening before (UTC, = 23:30 in Prague): knew the old navigation
        self::assertTrue($pointers->isActive($this->viewer(registeredAt: new DateTimeImmutable('2026-11-01 22:30:00', new DateTimeZone('UTC'))), HintType::NavigationInboxPointer));
        // Registered at midnight of the launch day in Prague, although it was still the 1st in UTC
        self::assertFalse($pointers->isActive($this->viewer(registeredAt: new DateTimeImmutable('2026-11-01 23:00:00', new DateTimeZone('UTC'))), HintType::NavigationInboxPointer));
        self::assertFalse($pointers->isActive($this->viewer(registeredAt: new DateTimeImmutable('2026-11-05 09:00:00')), HintType::NavigationInboxPointer));
        // An old account without a registration date
        self::assertTrue($pointers->isActive($this->viewer(registeredAt: null), HintType::NavigationInboxPointer));
    }

    public function testTheInboxPointerOnlyForPlayersWhoFollowSomebody(): void
    {
        $pointers = $this->pointers(self::INSIDE);

        self::assertFalse($pointers->isActive($this->viewer(favorites: []), HintType::NavigationInboxPointer));
        self::assertTrue($pointers->isActive($this->viewer(favorites: [PlayerFixture::PLAYER_REGULAR]), HintType::NavigationInboxPointer));
    }

    public function testNeverForAGuest(): void
    {
        self::assertFalse($this->pointers(self::INSIDE)->isActive(null, HintType::NavigationInboxPointer));
    }

    /**
     * LAUNCH-03: the launch pointer (Hub banner + Menu line) has no audience of its own - every old account, whether it
     * follows somebody or not - and its own dismissal, apart from the Inbox pointer's (decision 52: one dismissal hides
     * both of ITS places).
     */
    public function testTheLaunchPointer(): void
    {
        $pointers = $this->pointers(self::INSIDE);

        self::assertTrue($pointers->isActive($this->viewer(favorites: []), HintType::NavigationLaunchPointer));
        self::assertTrue($pointers->isActive($this->viewer(dismissed: [HintType::NavigationInboxPointer]), HintType::NavigationLaunchPointer));
        self::assertFalse($pointers->isActive($this->viewer(dismissed: [HintType::NavigationLaunchPointer]), HintType::NavigationLaunchPointer));
        self::assertTrue($pointers->isActive($this->viewer(dismissed: [HintType::NavigationLaunchPointer]), HintType::NavigationInboxPointer));
        self::assertFalse($pointers->isActive($this->viewer(registeredAt: new DateTimeImmutable('2026-11-05 09:00:00')), HintType::NavigationLaunchPointer));
        self::assertFalse($this->pointers('2026-12-02 00:00:00')->isActive($this->viewer(), HintType::NavigationLaunchPointer));
        self::assertFalse($pointers->isActive(null, HintType::NavigationLaunchPointer));
        self::assertFalse((new NavigationPointers(new NavigationLaunch(new MockClock(self::INSIDE, 'Europe/Prague'), null)))->isActive($this->viewer(), HintType::NavigationLaunchPointer));
        self::assertTrue(HintType::NavigationLaunchPointer->isNavigationPointer());
    }

    public function testTheTwigNames(): void
    {
        self::assertSame(HintType::NavigationInboxPointer, NavigationPointers::byName('inbox'));
        self::assertSame(HintType::NavigationLaunchPointer, NavigationPointers::byName('launch'));

        $this->expectException(\InvalidArgumentException::class);
        NavigationPointers::byName('marketplace');
    }

    public function testOnlyNavigationPointersAreDecidedHere(): void
    {
        self::assertTrue(HintType::NavigationInboxPointer->isNavigationPointer());
        self::assertFalse(HintType::MarketplaceDisclaimer->isNavigationPointer());

        $this->expectException(\InvalidArgumentException::class);
        $this->pointers(self::INSIDE)->isActive($this->viewer(), HintType::MarketplaceDisclaimer);
    }

    public function testTheTwigFunctionShowsAGuestNothing(): void
    {
        $container = self::getContainer();
        // Inside the 30 days - no signed-in viewer here, so a guest: nothing
        $container->set(NavigationLaunch::class, new NavigationLaunch(new MockClock(self::INSIDE, 'Europe/Prague'), self::LAUNCH_DAY));

        self::assertSame('none', $container->get(Environment::class)->createTemplate("{{ navigation_pointer('inbox') ? 'shown' : 'none' }}")->render());
    }

    private function pointers(string $now): NavigationPointers
    {
        return new NavigationPointers(new NavigationLaunch(new MockClock($now, 'Europe/Prague'), self::LAUNCH_DAY));
    }

    /**
     * The fixture player who follows two players, with the facts the rule reads replaced.
     *
     * @param null|list<string> $favorites
     * @param list<HintType> $dismissed
     */
    private function viewer(
        null|DateTimeImmutable $registeredAt = new DateTimeImmutable(self::OLD_ACCOUNT),
        null|array $favorites = null,
        array $dismissed = [],
    ): PlayerProfile {
        $profile = self::getContainer()->get(GetPlayerProfile::class)->byId(PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertNotSame([], $profile->favoritePlayers);

        // PlayerProfile has only promoted constructor properties: its public properties are its constructor arguments
        $arguments = get_object_vars($profile);
        $arguments['registeredAt'] = $registeredAt;
        $arguments['favoritePlayers'] = $favorites ?? $profile->favoritePlayers;
        $arguments['dismissedNavigationHints'] = $dismissed;

        return new PlayerProfile(...$arguments); // @phpstan-ignore argument.type
    }
}
