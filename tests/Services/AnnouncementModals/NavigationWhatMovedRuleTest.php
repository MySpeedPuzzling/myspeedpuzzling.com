<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\AnnouncementModals;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\NavigationLaunch;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\AnnouncementModals\NavigationWhatMovedRule;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\AnnouncementModal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * "What moved" (docs/features/navigation LAUNCH-01): for accounts created before the launch day, from the launch day
 * on - never while NavigationLaunch::DATE is null. The launch service carries the date and the clock (decision R5).
 */
final class NavigationWhatMovedRuleTest extends KernelTestCase
{
    private const string LAUNCH_DAY = '2026-11-02';
    private const string OLD_ACCOUNT = '2025-05-01 10:00:00';

    public function testItIsTheNavigationModal(): void
    {
        self::assertSame(AnnouncementModal::NavigationWhatMoved, $this->rule('2026-11-10 12:00:00')->modal());
    }

    public function testNothingWhileTheDateIsNull(): void
    {
        $rule = new NavigationWhatMovedRule(new NavigationLaunch(new MockClock('2030-01-01 12:00:00', 'Europe/Prague'), null));

        self::assertFalse($rule->isEligible($this->viewer(new DateTimeImmutable(self::OLD_ACCOUNT)), new DateTimeImmutable()));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideMoments(): iterable
    {
        // now (Prague) => an old account gets it
        yield 'the evening before the launch' => ['2026-11-01 23:59:59', false];
        yield 'at midnight of the launch day' => ['2026-11-02 00:00:00', true];
        yield 'a week later' => ['2026-11-09 12:00:00', true];
        // No expiry: a player back after months still finds the bars new
        yield 'months later' => ['2027-04-01 08:00:00', true];
    }

    #[DataProvider('provideMoments')]
    public function testFromTheLaunchDayOn(string $now, bool $eligible): void
    {
        self::assertSame($eligible, $this->rule($now)->isEligible($this->viewer(new DateTimeImmutable(self::OLD_ACCOUNT)), new DateTimeImmutable()));
    }

    public function testOnlyAccountsCreatedBeforeTheLaunchDay(): void
    {
        $rule = $this->rule('2026-11-10 12:00:00');
        $now = new DateTimeImmutable();

        // Registered the evening before (UTC, = 23:30 in Prague): knew the old navigation
        self::assertTrue($rule->isEligible($this->viewer(new DateTimeImmutable('2026-11-01 22:30:00', new DateTimeZone('UTC'))), $now));
        // Registered at midnight of the launch day in Prague, although still the 1st in UTC: never saw the old menus
        self::assertFalse($rule->isEligible($this->viewer(new DateTimeImmutable('2026-11-01 23:00:00', new DateTimeZone('UTC'))), $now));
        self::assertFalse($rule->isEligible($this->viewer(new DateTimeImmutable('2026-11-05 09:00:00', new DateTimeZone('UTC'))), $now));
        // A profile without its registration date: nobody can tell - no modal (the plan's LAUNCH-01 rule)
        self::assertFalse($rule->isEligible($this->viewer(null), $now));
    }

    public function testTheLaunchModalsIgnoreThePause(): void
    {
        self::assertTrue(AnnouncementModal::NavigationWhatMoved->ignoresCooldown());
        self::assertTrue(AnnouncementModal::XpLaunchReveal->ignoresCooldown());
        self::assertFalse(AnnouncementModal::FreeTrialOffer->ignoresCooldown());
        // Enum order is the priority (decision 57: the XP reveal, What moved, then the free trial) - AnnouncementModalTest
        // shows them in that order
        self::assertSame('modals/announcements/_navigation_what_moved.html.twig', AnnouncementModal::NavigationWhatMoved->template());
    }

    public function testThePicturesAreNoScreenshots(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../templates/modals/announcements/_navigation_what_moved.html.twig');

        self::assertStringNotContainsString('<img', $template);
        self::assertStringContainsString("path('where_did_it_go')", $template);
    }

    private function rule(string $now): NavigationWhatMovedRule
    {
        return new NavigationWhatMovedRule(new NavigationLaunch(new MockClock($now, 'Europe/Prague'), self::LAUNCH_DAY));
    }

    /**
     * A fixture player's profile with its registration date replaced.
     */
    private function viewer(null|DateTimeImmutable $registeredAt): PlayerProfile
    {
        $profile = self::getContainer()->get(GetPlayerProfile::class)->byId(PlayerFixture::PLAYER_REGULAR);

        // PlayerProfile has only promoted constructor properties: its public properties are its constructor arguments
        $arguments = get_object_vars($profile);
        $arguments['registeredAt'] = $registeredAt;

        return new PlayerProfile(...$arguments); // @phpstan-ignore argument.type
    }
}
