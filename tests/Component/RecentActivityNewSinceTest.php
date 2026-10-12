<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Component\RecentActivity;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\MarkFavoritesSeen;
use SpeedPuzzling\Web\MessageHandler\MarkFavoritesSeenHandler;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\FavoritesSeen;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * "New since your last visit" on the Hub's Favorites feed (docs/features/navigation, INB-04a): the Hub hands the
 * profile row's last visit to the lazily loaded feed as `newSince`, the feed's rows tracked after it are new and
 * counted, and marking the feed seen changes the stored value - never this page's new rows.
 */
final class RecentActivityNewSinceTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string FAVORITES_PANE = '#recent-favorites-activity';

    public function testNewerRowsAreNewAndMarkingSeenKeepsThem(): void
    {
        $browser = self::createClient();
        // One container for the whole test, so every request reads the same mocked clock
        $browser->disableReboot();
        $clock = new MockClock();
        self::getContainer()->set(ClockInterface::class, $clock);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        // Before: the fixture times - the last visit is the newest time of all (only strictly newer rows are new)
        $newestFixtureTime = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT MAX(tracked_at) FROM puzzle_solving_time');
        self::assertIsString($newestFixtureTime);
        $lastVisit = new DateTimeImmutable($newestFixtureTime);
        $this->storeLastVisit($lastVisit);

        // After: each favorite adds a time an hour later
        $clock->modify('@' . ($lastVisit->getTimestamp() + 3600));
        $newTimeIds = [
            $this->addTime('auth0|regular001', PuzzleFixture::PUZZLE_500_02),
            $this->addTime('auth0|admin003', PuzzleFixture::PUZZLE_1000_03),
        ];
        sort($newTimeIds);

        // The Hub reads the last visit from the profile row and hands it to the feed - before anything updates it
        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        $pane = $crawler->filter(self::FAVORITES_PANE);
        self::assertSame('favorites-seen', $pane->attr('data-controller'));
        self::assertSame((string) $lastVisit->getTimestamp(), $pane->attr('data-favorites-seen-new-since-value'));
        self::assertSame((string) FavoritesSeen::MARK_EVERY_SECONDS, $pane->attr('data-favorites-seen-interval-value'));
        self::assertSame($lastVisit->format(DateTimeInterface::RFC3339), self::feedProps($pane)['newSince'] ?? null);

        // INB-04b: the Favorites feed renders with the page - its tab says "2 new", exactly those rows are highlighted,
        // each with a "New" for screen readers
        $pill = $crawler->filter('.nav-tabs a[href="#recent-favorites-activity"] .hub-feed-new');
        self::assertSame('2 new', $pill->filter('.hub-feed-new-full')->text());
        self::assertSame('2', $pill->filter('.hub-feed-new-short[aria-hidden="true"]')->text());
        self::assertSame($newTimeIds, self::highlightedRowIds($pane));
        self::assertSame(['New', 'New'], $pane->filter('tr.ra-new .ps-info > .visually-hidden')->each(static fn (Crawler $label): string => $label->text()));

        // The feed as an automatic refresh renders it: the two new times are new, every fixture time is not
        $feed = $this->createLiveComponent(RecentActivity::class, [
            'limit' => 20,
            'showLimit' => 4,
            'favoritesOnly' => true,
            'autoRefresh' => true,
            'newSince' => $lastVisit,
        ], $browser)->setRouteLocale('en');
        $feed->render();
        self::assertSame($newTimeIds, self::newRowIds($feed));
        self::assertSame(2, self::component($feed)->getNewCount());

        self::assertSame($newTimeIds, self::highlightedRowIds($feed->render()->crawler()));

        // Marking the feed seen stores now - this render keeps its last visit, its new rows and its count
        $feed->call('markFavoritesSeen');
        self::assertSame($lastVisit->format(DateTimeInterface::RFC3339), self::component($feed)->newSince?->format(DateTimeInterface::RFC3339));
        self::assertSame($newTimeIds, self::newRowIds($feed));
        self::assertSame($newTimeIds, self::highlightedRowIds($feed->render()->crawler()));
        self::assertSame(2, self::component($feed)->getNewCount());
        self::assertSame(
            $clock->now()->format('Y-m-d H:i:s'),
            self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
                'SELECT favorites_seen_at FROM player WHERE id = :id',
                ['id' => PlayerFixture::PLAYER_WITH_FAVORITES],
            ),
        );

        // An automatic refresh of the same page keeps them too
        $feed->refresh();
        self::assertSame($newTimeIds, self::newRowIds($feed));
        self::assertSame($newTimeIds, self::highlightedRowIds($feed->render()->crawler()));

        // The next visit starts from the stored mark: nothing is new, no count
        $crawler = $browser->request('GET', '/en/hub');
        self::assertCount(0, $crawler->filter('.hub-feed-new'));
        self::assertSame([], self::highlightedRowIds($crawler->filter(self::FAVORITES_PANE)));
    }

    public function testWithoutALastVisitNothingIsNewAndNothingIsCounted(): void
    {
        $browser = self::createClient();
        // component() hydrates with the services of the container that served the last request
        $browser->disableReboot();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        $pane = $crawler->filter(self::FAVORITES_PANE);
        self::assertSame('0', $pane->attr('data-favorites-seen-new-since-value'));
        self::assertNull(self::feedProps($pane)['newSince'] ?? null);

        $feed = $this->createLiveComponent(RecentActivity::class, [
            'limit' => 20,
            'favoritesOnly' => true,
        ], $browser)->setRouteLocale('en');
        $feed->render();

        self::assertNotEmpty(self::component($feed)->getItems());
        self::assertSame([], self::newRowIds($feed));
        self::assertNull(self::component($feed)->getNewCount());
    }

    public function testOnlyTheFavoritesFeedOfAPlayerWithFavoritesMarksAnything(): void
    {
        // Guests: no per-visitor attributes in the shared HTML
        $browser = self::createClient();
        $browser->disableReboot();
        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter(self::FAVORITES_PANE)->attr('data-controller'));
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'));

        // Nobody followed yet: nothing to see, so nothing is marked (the first follow starts without a last visit)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter(self::FAVORITES_PANE)->attr('data-controller'));

        // The everybody feed never counts anything as new, and its action changes nothing
        $feed = $this->createLiveComponent(RecentActivity::class, ['limit' => 20, 'newSince' => new DateTimeImmutable('2000-01-01')], $browser)->setRouteLocale('en');
        $feed->render();
        self::assertNotEmpty(self::component($feed)->getItems());
        self::assertSame([], self::newRowIds($feed));
        self::assertNull(self::component($feed)->getNewCount());
        $feed->call('markFavoritesSeen');
        self::assertFalse(self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT favorites_seen_at IS NOT NULL FROM player WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        ));
    }

    private function storeLastVisit(DateTimeImmutable $lastVisit): void
    {
        $container = self::getContainer();
        $handler = new MarkFavoritesSeenHandler($container->get(PlayerRepository::class), new MockClock($lastVisit));
        $handler(new MarkFavoritesSeen(PlayerFixture::PLAYER_WITH_FAVORITES));
        $container->get(EntityManagerInterface::class)->flush();
    }

    private function addTime(string $userId, string $puzzleId): string
    {
        $timeId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: '01:23:45',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private static function feedProps(Crawler $pane): array
    {
        $props = $pane->filter('[data-live-name-value="RecentActivity"]')->attr('data-live-props-value');
        self::assertNotNull($props);
        $decoded = json_decode($props, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<string> newest first, as the feed lists them
     */
    private static function newRowIds(TestLiveComponent $feed): array
    {
        $component = self::component($feed);
        $ids = [];

        foreach ($component->getItems() as $item) {
            if ($component->isNew($item)) {
                $ids[] = $item->id;
            }
        }

        // Both new times share their second: compare them as a set
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string> the ids of the rows rendered as new, sorted
     */
    private static function highlightedRowIds(Crawler $crawler): array
    {
        $ids = $crawler->filter('tr.ra-new[id^="activity-favorites-"]')->each(
            static fn (Crawler $row): string => substr((string) $row->attr('id'), strlen('activity-favorites-')),
        );
        sort($ids);

        return $ids;
    }

    private static function component(TestLiveComponent $feed): RecentActivity
    {
        $component = $feed->component();
        assert($component instanceof RecentActivity);

        return $component;
    }
}
