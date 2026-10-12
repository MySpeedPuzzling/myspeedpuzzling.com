<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Results\RunningStopwatch;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The running-stopwatch pill partial (docs/features/navigation README D7, ADD-03) renders from the viewer's own profile
 * row - before any bar places it (NAV-03/NAV-04 include templates/navigation/_stopwatch_pill.html.twig). Lap starts are
 * set in the test relative to the clock (risk 17). PLAYER_WITH_STRIPE has no stopwatch in the fixtures.
 */
final class StopwatchPillRenderTest extends KernelTestCase
{
    private const string PLAYER_ID = PlayerFixture::PLAYER_WITH_STRIPE;

    private Connection $database;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->now = self::getContainer()->get(ClockInterface::class)->now();
        self::getContainer()->get(RouterInterface::class)->getContext()->setParameter('_locale', 'en');
        self::getContainer()->get(TranslatorInterface::class)->setLocale('en');
    }

    public function testThePillRendersFromTheViewersProfileRow(): void
    {
        // 15 closed minutes + 10 running = 25 minutes
        $stopwatchId = $this->insert(StopwatchStatus::Running, [['-1 hour', '-45 minutes'], ['-10 minutes', null]]);
        $this->signIn();

        $crawler = new Crawler($this->render());
        $pill = $crawler->filter('a[data-stopwatch-pill]');

        self::assertCount(1, $pill);
        self::assertSame('/en/stopwatch/' . $stopwatchId, $pill->attr('href'));
        self::assertSame('topbar/add/stopwatch_pill', $pill->attr('data-nav'));
        self::assertMatchesRegularExpression(NavClickController::NAV_PATTERN, (string) $pill->attr('data-nav'));
        self::assertSame('Stopwatch running', $pill->attr('aria-label'));
        self::assertSame('Back to your stopwatch', $pill->attr('title'));
        self::assertSame('true', $pill->filter('.stopwatch-pill-body')->attr('aria-hidden'));
        self::assertCount(1, $pill->filter('svg.stopwatch-pill-icon'));

        // The controller's numbers: what the browser counts on from
        $elapsed = $pill->filter('[data-controller="stopwatch-pill"]');
        self::assertCount(1, $elapsed);
        self::assertSame($stopwatchId, $elapsed->attr('data-stopwatch-pill-stopwatch-id-value'));
        self::assertSame((string) $this->now->modify('-10 minutes')->getTimestamp(), $elapsed->attr('data-stopwatch-pill-lap-started-value'));
        self::assertSame('900', $elapsed->attr('data-stopwatch-pill-closed-seconds-value'));
        $rendered = (int) $elapsed->attr('data-stopwatch-pill-rendered-value');
        self::assertEqualsWithDelta($this->now->getTimestamp(), $rendered, 5);

        // The server renders the digits (no empty flash) from the same numbers - the browser's first tick writes the same
        $seconds = 900 + $rendered - $this->now->modify('-10 minutes')->getTimestamp();
        $full = $elapsed->filter('.stopwatch-elapsed-full .stopwatch-elapsed-digits');
        $compact = $elapsed->filter('.stopwatch-elapsed-compact .stopwatch-elapsed-digits');
        self::assertSame(RunningStopwatch::format($seconds, false), $full->text());
        self::assertSame(RunningStopwatch::format($seconds, true), $compact->text());
        self::assertStringStartsWith('0:25:', $full->text());
        self::assertSame('full', $full->attr('data-stopwatch-pill-target'));
        self::assertSame('compact', $compact->attr('data-stopwatch-pill-target'));

        // The room fixed at render under the digits: the text plus one digit (no layout shift when 9:59:59 becomes
        // 10:00:00) - only the digits are a controller target, the room is never written
        self::assertSame('00:00:00', $elapsed->filter('.stopwatch-elapsed-full .stopwatch-elapsed-room')->text());
        self::assertSame('000:00', $elapsed->filter('.stopwatch-elapsed-compact .stopwatch-elapsed-room')->text());
        self::assertCount(0, $elapsed->filter('.stopwatch-elapsed-room[data-stopwatch-pill-target]'));
        // 25:00 keeps the icon below 360 px
        self::assertStringNotContainsString('stopwatch-pill--no-compact-icon', (string) $pill->attr('class'));
    }

    public function testALongRunDropsTheIconBelow360Pixels(): void
    {
        // 1:40:00 = 100:00 in m:ss - too long for the icon within 70 px, decided once, at render
        $this->insert(StopwatchStatus::Running, [['-100 minutes', null]]);
        $this->signIn();

        $pill = new Crawler($this->render())->filter('a[data-stopwatch-pill]');

        self::assertCount(1, $pill);
        self::assertStringContainsString('stopwatch-pill--no-compact-icon', (string) $pill->attr('class'));
        self::assertSame('0000:00', $pill->filter('.stopwatch-elapsed-compact .stopwatch-elapsed-room')->text());
    }

    /**
     * @return iterable<string, array{StopwatchStatus, list<array{string, null|string}>}>
     */
    public static function provideNoPill(): iterable
    {
        yield 'paused' => [StopwatchStatus::Paused, [['-20 minutes', '-10 minutes']]];
        yield 'finished' => [StopwatchStatus::Finished, [['-20 minutes', '-10 minutes']]];
        yield 'running for more than 24 hours' => [StopwatchStatus::Running, [['-24 hours -1 minute', null]]];
    }

    /**
     * @param list<array{string, null|string}> $laps
     */
    #[DataProvider('provideNoPill')]
    public function testNothingWithoutAFreshRunningStopwatch(StopwatchStatus $status, array $laps): void
    {
        $this->insert($status, $laps);
        $this->signIn();

        self::assertSame('', trim($this->render()));
    }

    public function testNothingForAGuest(): void
    {
        $this->insert(StopwatchStatus::Running, [['-10 minutes', null]]);

        self::assertSame('', trim($this->render()));
    }

    public function testAGivenStopwatchRendersWithoutTheProfileRow(): void
    {
        $stopwatch = RunningStopwatch::fromJson(
            (string) json_encode(['id' => '018d000d-0000-0000-0000-00000000aa01', 'lap_started_at' => $this->now->modify('-61 minutes')->format('Y-m-d H:i:s'), 'closed_seconds' => 0]),
            $this->now,
        );
        self::assertNotNull($stopwatch);

        $pill = new Crawler($this->render(['running_stopwatch' => $stopwatch]))->filter('a[data-stopwatch-pill]');

        self::assertSame('/en/stopwatch/018d000d-0000-0000-0000-00000000aa01', $pill->attr('href'));
        self::assertSame('1:01:00', $pill->filter('.stopwatch-elapsed-full .stopwatch-elapsed-digits')->text());
        self::assertSame('61:00', $pill->filter('.stopwatch-elapsed-compact .stopwatch-elapsed-digits')->text());
    }

    /**
     * The elapsed partial on its own - what the Menu's Stopwatch row (MENU-01) reuses.
     */
    public function testTheElapsedPartialStandsAlone(): void
    {
        $stopwatch = RunningStopwatch::fromJson(
            (string) json_encode(['id' => '018d000d-0000-0000-0000-00000000aa01', 'lap_started_at' => $this->now->modify('-42 minutes -17 seconds')->format('Y-m-d H:i:s'), 'closed_seconds' => 0]),
            $this->now,
        );
        self::assertNotNull($stopwatch);

        /** @var Environment $twig */
        $twig = self::getContainer()->get(Environment::class);
        $elapsed = new Crawler($twig->render('navigation/_stopwatch_elapsed.html.twig', ['stopwatch' => $stopwatch]))->filter('.stopwatch-elapsed');

        self::assertSame('stopwatch-pill', $elapsed->attr('data-controller'));
        self::assertSame('0:42:17', $elapsed->filter('.stopwatch-elapsed-full .stopwatch-elapsed-digits')->text());
        self::assertSame('42:17', $elapsed->filter('.stopwatch-elapsed-compact .stopwatch-elapsed-digits')->text());
        self::assertSame(['full', 'compact'], $elapsed->filter('[data-stopwatch-pill-target]')->each(static fn (Crawler $target): string => (string) $target->attr('data-stopwatch-pill-target')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocales(): iterable
    {
        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('provideLocales')]
    public function testThePillIsWordedInEveryLanguage(string $locale): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $catalogue = $translator->getCatalogue($locale);

        foreach (['stopwatch.pill.label', 'stopwatch.pill.title'] as $key) {
            self::assertTrue($catalogue->defines($key), "{$locale}: {$key}");

            if ($locale !== 'en') {
                self::assertNotSame($translator->trans($key, [], null, 'en'), $translator->trans($key, [], null, $locale), "{$locale}: {$key}");
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context = []): string
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get(Environment::class);

        return $twig->render('navigation/_stopwatch_pill.html.twig', $context);
    }

    private function signIn(): void
    {
        $container = self::getContainer();
        $userAccount = $container->get(UserAccountRepository::class)->findByUserId(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID);

        self::assertNotNull($userAccount, 'UserAccountFixture gives PLAYER_WITH_STRIPE an account');

        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($userAccount, 'main', $userAccount->getRoles()));
        $container->get(RetrieveLoggedUserProfile::class)->reset();
    }

    /**
     * @param list<array{string, null|string}> $laps [start, end] relative to the clock
     */
    private function insert(StopwatchStatus $status, array $laps): string
    {
        $id = Uuid::uuid7()->toString();

        $this->database->insert('stopwatch', [
            'id' => $id,
            'player_id' => self::PLAYER_ID,
            'puzzle_id' => null,
            'status' => $status->value,
            'laps' => json_encode(array_map(
                fn (array $lap): array => [
                    'start' => $this->now->modify($lap[0])->format('Y-m-d H:i:s'),
                    'end' => $lap[1] === null ? null : $this->now->modify($lap[1])->format('Y-m-d H:i:s'),
                ],
                $laps,
            ), JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }
}
