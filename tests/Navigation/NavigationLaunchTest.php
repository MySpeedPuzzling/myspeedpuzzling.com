<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\NavigationLaunch;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Twig\Environment;

/**
 * The launch date (LAUNCH-00): null until the owner sets it - no "What moved", no pointers, "New" badges shown; once
 * set, the pointers live 30 days and the badges 14 days from midnight of the launch day in Prague.
 */
final class NavigationLaunchTest extends KernelTestCase
{
    private const string LAUNCH_DAY = '2026-11-02';

    public function testNullDateMeansNotLaunched(): void
    {
        $launch = new NavigationLaunch(new MockClock('2030-01-01 12:00:00', 'Europe/Prague'), null);

        self::assertNull($launch->date());
        self::assertFalse($launch->hasLaunched());
        self::assertFalse($launch->pointersActiveFor($this->profile(null)));
        self::assertFalse($launch->accountPredatesLaunch(new DateTimeImmutable('2020-01-01')));
        self::assertTrue($launch->newBadgesVisible());
    }

    public function testTheContainerReadsTheConstantThroughItsParameter(): void
    {
        $container = self::getContainer();

        // assertEquals: static analysis reads both sides from the compiled container and the constant's value
        self::assertEquals(NavigationLaunch::DATE, $container->getParameter('navigation.launch_date'));
        self::assertEquals(NavigationLaunch::DATE, $container->get(NavigationLaunch::class)->date()?->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{string, bool, bool, bool}>
     */
    public static function provideMoments(): iterable
    {
        // moment (Prague) => launched, pointers active for an older account, "New" badges visible
        yield 'the evening before' => ['2026-11-01 23:59:59', false, false, true];
        yield 'at midnight of the launch day' => ['2026-11-02 00:00:00', true, true, true];
        yield 'the day after' => ['2026-11-03 10:00:00', true, true, true];
        yield 'the last second of the badges' => ['2026-11-15 23:59:59', true, true, true];
        yield '+14 days: badges gone' => ['2026-11-16 00:00:00', true, true, false];
        yield 'the last second of the pointers' => ['2026-12-01 23:59:59', true, true, false];
        yield '+30 days: pointers gone' => ['2026-12-02 00:00:00', true, false, false];
        yield 'months later' => ['2027-03-01 08:00:00', true, false, false];
    }

    #[DataProvider('provideMoments')]
    public function testASetDate(string $now, bool $launched, bool $pointers, bool $badges): void
    {
        $launch = new NavigationLaunch(new MockClock($now, 'Europe/Prague'), self::LAUNCH_DAY);

        self::assertEquals(new DateTimeImmutable(self::LAUNCH_DAY . ' 00:00:00', new DateTimeZone('Europe/Prague')), $launch->date());
        self::assertSame($launched, $launch->hasLaunched());
        self::assertSame($pointers, $launch->pointersActiveFor($this->profile(new DateTimeImmutable('2025-05-01 10:00:00'))));
        self::assertSame($badges, $launch->newBadgesVisible());
    }

    public function testPointersOnlyForAccountsThatKnewTheOldNavigation(): void
    {
        $launch = new NavigationLaunch(new MockClock('2026-11-10 12:00:00', 'Europe/Prague'), self::LAUNCH_DAY);

        // Registered the evening before (UTC, = 23:30 in Prague): knew the old navigation
        self::assertTrue($launch->pointersActiveFor($this->profile(new DateTimeImmutable('2026-11-01 22:30:00', new DateTimeZone('UTC')))));
        // Registered after midnight in Prague, although it was still the 1st in UTC
        self::assertFalse($launch->pointersActiveFor($this->profile(new DateTimeImmutable('2026-11-01 23:30:00', new DateTimeZone('UTC')))));
        self::assertFalse($launch->pointersActiveFor($this->profile(new DateTimeImmutable('2026-11-05 09:00:00'))));
        // An old account without a registration date
        self::assertTrue($launch->pointersActiveFor($this->profile(null)));
        self::assertTrue($launch->accountPredatesLaunch(null));
    }

    public function testAnInvalidDateIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NavigationLaunch(new MockClock(), '2026-13-45');
    }

    public function testTheNewBadgesTwigFunctionFollowsTheService(): void
    {
        $container = self::getContainer();
        $container->set(NavigationLaunch::class, new NavigationLaunch(new MockClock('2026-11-20 12:00:00', 'Europe/Prague'), self::LAUNCH_DAY));

        $template = $container->get(Environment::class)->createTemplate("{{ nav_new_badges_shown() ? 'badges' : 'no-badges' }}");

        self::assertSame('no-badges', $template->render());
    }

    private function profile(null|DateTimeImmutable $registeredAt): PlayerProfile
    {
        $profile = self::getContainer()->get(GetPlayerProfile::class)->byId(PlayerFixture::PLAYER_REGULAR);

        // PlayerProfile has only promoted constructor properties: its public properties are its constructor arguments
        $arguments = get_object_vars($profile);
        $arguments['registeredAt'] = $registeredAt;

        return new PlayerProfile(...$arguments); // @phpstan-ignore argument.type
    }
}
