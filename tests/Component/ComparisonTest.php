<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Component\Comparison;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonSubjectFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The live actions of the compare page (docs/features/player-comparison.md): the line-up changes, the view switch, the
 * preview's "Add to my line-up" - and that none of them ever throws at the visitor.
 */
final class ComparisonTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string STRIPE_REF = 'p-' . PlayerFixture::PLAYER_WITH_STRIPE;
    private const string ADMIN_REF = 'p-' . PlayerFixture::PLAYER_ADMIN;
    private const string REGULAR_REF = 'p-' . PlayerFixture::PLAYER_REGULAR;
    private const string PRIVATE_REF = 'p-' . PlayerFixture::PLAYER_PRIVATE;

    public function testTheViewIsRememberedForEveryComparison(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->mount($client, ['kind' => 'solo'])->call('changeView', ['view' => 'table'])->render()->crawler();

        self::assertCount(1, $crawler->filter('[data-testid="comparison-table"]'));
        self::assertSame('true', $crawler->filter('[data-testid="comparison-view-table"]')->attr('aria-pressed'));
        self::assertSame('Table', $crawler->filter('[data-testid="comparison-view-table"]')->attr('aria-label'));
        self::assertSame('table', $this->storedView(PlayerFixture::PLAYER_WITH_STRIPE));

        // A new page opens on it
        $client->request('GET', '/en/compare?kind=solo');
        self::assertSelectorExists('[data-testid="comparison-table"]');

        $crawler = $this->mount($client, ['kind' => 'solo'])->call('changeView', ['view' => 'duel'])->render()->crawler();
        self::assertCount(1, $crawler->filter('[data-testid="comparison-duel-rows"]'));
        self::assertSame('duel', $this->storedView(PlayerFixture::PLAYER_WITH_STRIPE));

        // Nonsense changes nothing
        $this->mount($client, ['kind' => 'solo'])->call('changeView', ['view' => 'pie']);
        self::assertSame('duel', $this->storedView(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testAddingSomebodyBringsYouIntoTheSoloLineUpToo(): void
    {
        $client = self::createClient();
        // A member with an empty line-up: just "you", nothing to compare
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_ADMIN);

        $component = $this->mount($client, ['kind' => 'solo']);
        self::assertSame(['You'], self::chipNames($component->render()->crawler()));

        $crawler = $component->call('add', ['ref' => self::REGULAR_REF])->render()->crawler();

        self::assertSame(['You', 'John Doe'], self::chipNames($crawler));
        self::assertSame(2, $this->lineUpSize(PlayerFixture::PLAYER_ADMIN));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-head-to-head"]'));
    }

    public function testAFullFreeLineUpOffersTheSwapInsteadOfAnError(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        $component = $this->mount($client, ['kind' => 'solo']);
        $crawler = $component->call('add', ['ref' => self::ADMIN_REF])->render()->crawler();

        $comparison = $component->component();
        self::assertInstanceOf(Comparison::class, $comparison);
        self::assertSame(self::ADMIN_REF, $comparison->swap);
        self::assertSame('true', $crawler->filter('[data-testid="comparison-swap"]')->attr('data-comparison-sheet-open-value'));
        self::assertSame(['You', 'Sarah Williams'], self::chipNames($crawler));

        // "Swap Sarah for Admin"
        $crawler = $component->call('add', ['ref' => self::ADMIN_REF, 'replaceRowId' => ComparisonSubjectFixture::REGULAR_STRIPE])->render()->crawler();

        self::assertSame(['You', 'Admin User'], self::chipNames($crawler));
        self::assertSame('false', $crawler->filter('[data-testid="comparison-swap"]')->attr('data-comparison-sheet-open-value'));
        self::assertSame(2, $this->lineUpSize(PlayerFixture::PLAYER_REGULAR));
    }

    public function testKeepingTheLineUpClosesTheSwap(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        $component = $this->mount($client, ['swap' => self::ADMIN_REF]);
        self::assertSame('true', $component->render()->crawler()->filter('[data-testid="comparison-swap"]')->attr('data-comparison-sheet-open-value'));

        $crawler = $component->call('dismissSwap')->render()->crawler();

        self::assertSame('false', $crawler->filter('[data-testid="comparison-swap"]')->attr('data-comparison-sheet-open-value'));
        self::assertSame(['You', 'Sarah Williams'], self::chipNames($crawler));
    }

    public function testSomebodyHiddenFromTheViewerIsAQuietNotice(): void
    {
        $client = self::createClient();
        // PLAYER_REGULAR blocks PLAYER_PRIVATE
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->mount($client, ['kind' => 'solo'])->call('add', ['ref' => self::PRIVATE_REF])->render()->crawler();

        self::assertSame("This one can't be compared.", trim($crawler->filter('[data-testid="comparison-notice"]')->text()));
        self::assertSame(2, $this->lineUpSize(PlayerFixture::PLAYER_REGULAR));

        // Made-up refs are ignored
        $this->mount($client, ['kind' => 'solo'])->call('add', ['ref' => 'x-nonsense']);
        self::assertSame(2, $this->lineUpSize(PlayerFixture::PLAYER_REGULAR));
    }

    public function testAMemberAtTheCapGetsTheSwapAndMaySwapThemselvesOut(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $database = self::getContainer()->get(Connection::class);

        for ($i = 1; $i <= 7; $i++) {
            $playerId = sprintf('018d00ff-0000-0000-0000-%012d', $i);
            $database->executeStatement(
                "INSERT INTO player (id, code, name, registered_at, is_private) VALUES (:id, :code, :name, NOW(), false)",
                ['id' => $playerId, 'code' => 'capfill' . $i, 'name' => 'Cap filler ' . $i],
            );
            $database->executeStatement(
                'INSERT INTO comparison_subject (id, player_id, subject_player_id, added_at) VALUES (:id, :owner, :subject, NOW())',
                ['id' => sprintf('018d00fe-0000-0000-0000-%012d', $i), 'owner' => PlayerFixture::PLAYER_WITH_STRIPE, 'subject' => $playerId],
            );
        }

        $component = $this->mount($client, ['kind' => 'solo']);
        $crawler = $component->call('add', ['ref' => 'p-' . PlayerFixture::PLAYER_WITH_FAVORITES])->render()->crawler();

        // Never a dead end, for members neither: the same swap prompt as the entry points' `?swap=`
        $swap = $crawler->filter('[data-testid="comparison-swap"]');
        self::assertSame('true', $swap->attr('data-comparison-sheet-open-value'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-notice"]'));
        self::assertStringContainsString('Your comparison holds up to 10 players.', $swap->text());
        self::assertCount(0, $swap->filter('.cmp-swap__members'), 'No membership line for a member');

        // Any of the ten makes room - yourself included
        $confirm = $crawler->filter('[data-testid="comparison-swap-confirm"]');
        self::assertCount(10, $confirm);
        self::assertSame('Swap yourself out for Michael Johnson', trim($confirm->first()->text()));
        self::assertSame(ComparisonSubjectFixture::STRIPE_SELF, $confirm->first()->attr('data-live-replace-row-id-param'));

        $crawler = $component->call('add', ['ref' => 'p-' . PlayerFixture::PLAYER_WITH_FAVORITES, 'replaceRowId' => ComparisonSubjectFixture::STRIPE_SELF])->render()->crawler();

        self::assertSame('false', $crawler->filter('[data-testid="comparison-swap"]')->attr('data-comparison-sheet-open-value'));
        self::assertSame(10, $this->lineUpSize(PlayerFixture::PLAYER_WITH_STRIPE) - 1, 'Solo is still full, the pair line-up holds one');
        self::assertContains('Michael Johnson', self::chipNames($crawler));
        self::assertNotContains('You', self::chipNames($crawler));
    }

    public function testRemovingSomebody(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->mount($client, ['kind' => 'solo'])->call('remove', ['rowId' => ComparisonSubjectFixture::STRIPE_ADMIN])->render()->crawler();

        self::assertSame(['You', 'John Doe'], self::chipNames($crawler));
        self::assertSame(3, $this->lineUpSize(PlayerFixture::PLAYER_WITH_STRIPE));

        // Somebody else's row is none of hers - nothing happens, nothing breaks
        $crawler = $this->mount($client, ['kind' => 'solo'])->call('remove', ['rowId' => ComparisonSubjectFixture::REGULAR_STRIPE])->render()->crawler();
        self::assertSame(['You', 'John Doe'], self::chipNames($crawler));
        self::assertSame(2, $this->lineUpSize(PlayerFixture::PLAYER_REGULAR));
    }

    public function testWithoutAMembershipYouStayInYourOwnLineUp(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->mount($client, ['kind' => 'solo'])->call('remove', ['rowId' => ComparisonSubjectFixture::REGULAR_SELF])->render()->crawler();

        self::assertSame('Without a membership you stay in your own Solo line-up.', trim($crawler->filter('[data-testid="comparison-notice"]')->text()));
        self::assertSame(['You', 'Sarah Williams'], self::chipNames($crawler));
    }

    public function testAddingASharedComparisonToTheLineUp(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_ADMIN);

        $component = $this->mount($client, ['with' => self::STRIPE_REF . ',' . self::REGULAR_REF]);
        self::assertCount(1, $component->render()->crawler()->filter('[data-testid="comparison-preview"]'));

        $crawler = $component->call('addPreview')->render()->crawler();

        self::assertCount(0, $crawler->filter('[data-testid="comparison-preview"]'));
        self::assertSame('Added to your line-up: 2.', trim($crawler->filter('[data-testid="comparison-notice"]')->text()));
        self::assertSame(['You', 'Sarah Williams', 'John Doe'], self::chipNames($crawler));
        self::assertSame(3, $this->lineUpSize(PlayerFixture::PLAYER_ADMIN));

        $comparison = $component->component();
        self::assertInstanceOf(Comparison::class, $comparison);
        self::assertNull($comparison->with);
    }

    public function testAFreeLineUpTakesTheSharedComparisonUpToItsCap(): void
    {
        $client = self::createClient();
        // Free, Solo at the cap already
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        $component = $this->mount($client, ['with' => self::ADMIN_REF]);
        $crawler = $component->call('addPreview')->render()->crawler();

        self::assertSame('Your line-up is full - nothing was added.', trim($crawler->filter('[data-testid="comparison-notice"]')->text()));
        // ... and the swap is offered for whoever did not fit
        self::assertSame('true', $crawler->filter('[data-testid="comparison-swap"]')->attr('data-comparison-sheet-open-value'));
        self::assertSame(2, $this->lineUpSize(PlayerFixture::PLAYER_REGULAR));
    }

    public function testFiltersAreActions(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $component = $this->mount($client, ['kind' => 'solo']);
        $component->call('chooseShow', ['value' => 'all']);
        $component->call('toggleFirstTries');
        $component->call('toggleDifficulty', ['tier' => '3']);
        $component->call('choosePeriod', ['value' => '12m']);
        $crawler = $component->render()->crawler();

        $comparison = $component->component();
        self::assertInstanceOf(Comparison::class, $comparison);
        self::assertSame('all', $comparison->show);
        self::assertSame('first', $comparison->times);
        self::assertSame(['3'], $comparison->difficulty);
        self::assertSame('12m', $comparison->period);
        // show, times, period, difficulty
        self::assertSame('4', trim($crawler->filter('[data-testid="comparison-filters-button"] .cmp-pill__count')->text()));
    }

    public function testCustomRangeIsForMembers(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $component = $this->mount($client, ['kind' => 'solo']);
        $crawler = $component->call('choosePeriod', ['value' => 'custom'])->render()->crawler();
        self::assertCount(2, $crawler->filter('#comparison-filters .date-picker'));

        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->mount($client, ['kind' => 'solo'])->call('choosePeriod', ['value' => 'custom'])->render()->crawler();
        self::assertCount(0, $crawler->filter('#comparison-filters .date-picker'));
    }

    public function testTheChartsTabIsForMembers(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->mount($client, ['kind' => 'solo'])->call('changeTab', ['tab' => 'charts'])->render()->crawler();

        self::assertCount(1, $crawler->filter('[data-testid="comparison-charts-locked"]'));
        self::assertSame('true', $crawler->filter('[data-testid="comparison-tab-charts"]')->attr('aria-selected'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-row"]'));

        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->mount($client, ['kind' => 'solo'])->call('changeTab', ['tab' => 'charts'])->render()->crawler();
        self::assertCount(0, $crawler->filter('[data-testid="comparison-charts-locked"]'));
    }

    public function testEmptyResultOffersAllPuzzlesInOneTap(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        // Nothing of 2000+ pieces solved by two of them
        $component = $this->mount($client, ['kind' => 'solo', 'pieces' => '5000-6000']);
        $crawler = $component->render()->crawler();
        self::assertCount(1, $crawler->filter('[data-testid="comparison-empty-show-all"]'));

        $component->call('showAll');
        $comparison = $component->component();
        self::assertInstanceOf(Comparison::class, $comparison);
        self::assertSame('all', $comparison->show);
    }

    public function testTheSimilarSpeedPickAddsToTheLineUp(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->mount($client, ['kind' => 'solo'])->emit('comparisonAddSubject', ['ref' => self::STRIPE_REF])->render()->crawler();

        self::assertSame(['You', 'Sarah Williams'], self::chipNames($crawler));
    }

    public function testWithoutASignedInPlayerNothingHappens(): void
    {
        $client = self::createClient();

        $component = $this->mount($client, []);
        $component->call('add', ['ref' => self::REGULAR_REF]);
        $component->call('remove', ['rowId' => ComparisonSubjectFixture::REGULAR_STRIPE]);
        $component->call('addPreview');
        $component->call('changeView', ['view' => 'table']);
        $crawler = $component->render()->crawler();

        self::assertCount(0, $crawler->filter('[data-testid="comparison-line-up"]'));
        self::assertSame(2, $this->lineUpSize(PlayerFixture::PLAYER_REGULAR));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mount(KernelBrowser $client, array $data): TestLiveComponent
    {
        $component = $this->createLiveComponent('Comparison', $data, $client);
        $component->setRouteLocale('en');

        return $component;
    }

    /**
     * @return list<string>
     */
    private static function chipNames(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid="comparison-chip"] .cmp-chip__name')->each(
            static fn (Crawler $name): string => trim($name->text()),
        );
    }

    private function lineUpSize(string $playerId): int
    {
        $count = self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM comparison_subject WHERE player_id = :id', ['id' => $playerId]);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function storedView(string $playerId): mixed
    {
        return self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT comparison_view FROM player WHERE id = :id', ['id' => $playerId]);
    }
}
