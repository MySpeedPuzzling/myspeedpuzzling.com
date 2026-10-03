<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
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

        // Table is the default, first in the switch, both with a visible label
        $crawler = $this->mount($client, ['kind' => 'solo'])->render()->crawler();
        self::assertCount(1, $crawler->filter('[data-testid="comparison-table"]'));
        self::assertSame(['Table', 'Cards'], $crawler->filter('[data-testid="comparison-view-switch"] button')->each(
            static fn (Crawler $button): string => trim($button->text()),
        ));
        self::assertSame('true', $crawler->filter('[data-testid="comparison-view-table"]')->attr('aria-pressed'));

        $crawler = $this->mount($client, ['kind' => 'solo'])->call('changeView', ['view' => 'cards'])->render()->crawler();

        self::assertCount(1, $crawler->filter('[data-testid="comparison-cards"]'));
        self::assertSame('true', $crawler->filter('[data-testid="comparison-view-cards"]')->attr('aria-pressed'));
        self::assertSame('cards', $this->storedView(PlayerFixture::PLAYER_WITH_STRIPE));

        // A new page opens on it
        $client->request('GET', '/en/compare?kind=solo');
        self::assertSelectorExists('[data-testid="comparison-cards"]');

        // The removed Duel view and nonsense change nothing
        foreach (['duel', 'pie'] as $view) {
            $this->mount($client, ['kind' => 'solo'])->call('changeView', ['view' => $view]);
            self::assertSame('cards', $this->storedView(PlayerFixture::PLAYER_WITH_STRIPE));
        }
    }

    /**
     * Bug (2026-10-03): "150 puzzles in common", 100 listed, "Show more" then did nothing. After every render Live writes
     * each prop into its non-multiple <select data-model> and reads the select back: a null sort/period matches no option,
     * the browser falls back to the first option "", and every following request re-sends `sort: ""` / `period: ""` as
     * updated props. Their onUpdated hook reset the paging before showMore() ran - every click rendered page two again.
     * This drives the component the way the browser does, selects included.
     */
    public function testShowMoreListsEveryPuzzleEvenWithTheSelectsResentByTheBrowser(): void
    {
        $client = self::createClient();
        // Free: Solo = himself + PLAYER_WITH_STRIPE - side-by-side rows of the two
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);
        $this->seedSharedPuzzles(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE, 160);

        $crawler = $client->request('GET', '/en/compare?kind=solo');
        self::assertResponseIsSuccessful();
        $total = self::listedTotal($crawler);
        self::assertGreaterThanOrEqual(160, $total);
        self::assertSame($total, (int) trim($crawler->filter('[data-testid="comparison-head-to-head"] .cmp-h2h__line')->text()), 'The head to head says the same number');

        $rows = [50];

        while ($crawler->filter('[data-testid="comparison-show-more"]')->count() > 0) {
            self::assertLessThan(10, count($rows), '"Show more" never gets to the end');
            $crawler = $this->browserAction($client, $crawler, 'showMore');
            $rows[] = $crawler->filter('[data-testid="comparison-row"]')->count();
        }

        self::assertSame(range(50, intdiv($total, 50) * 50, 50), array_slice($rows, 0, intdiv($total, 50)), 'Every click adds a page');
        self::assertSame($total, $rows[array_key_last($rows)], 'Everything listed in the end');
        self::assertSame($total, self::listedTotal($crawler));
    }

    /**
     * The "Show more" pages belong to one list: another sort or filter starts on the first page again, the same list
     * (the view switch) keeps them
     */
    public function testAnotherListStartsOnItsFirstPage(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedSharedPuzzles(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN, 160);

        $component = $this->mount($client, ['kind' => 'solo']);
        $component->call('showMore')->call('showMore');
        self::assertCount(150, $component->render()->crawler()->filter('[data-testid="comparison-row"]'));

        self::assertCount(150, $component->call('changeView', ['view' => 'cards'])->render()->crawler()->filter('[data-testid="comparison-row"]'));

        self::assertCount(50, $component->call('chooseSort', ['value' => 'pieces'])->render()->crawler()->filter('[data-testid="comparison-row"]'));
    }

    /**
     * The pair (3+ subjects) only where something follows it: the charts and a lead/lag sort - worded plainly
     */
    public function testThePairPickerSitsWhereThePairMatters(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $component = $this->mount($client, ['kind' => 'solo']);
        $crawler = $component->render()->crawler();
        self::assertCount(0, $crawler->filter('[data-testid^="comparison-pair-"]'), 'Not for a plain list');
        self::assertCount(0, $crawler->filter('.cmp-ring-a, .cmp-ring-b'), 'Nobody marked as A or B while the pair plays no part');
        // The sort says whose lead it is
        self::assertSame('Biggest lead: You', trim($crawler->filter('#comparison-sort option[value="lead"]')->text()));

        $crawler = $component->call('chooseSort', ['value' => 'lead'])->render()->crawler();
        $line = $crawler->filter('[data-testid="comparison-pair-sort"]');
        self::assertCount(1, $line);
        self::assertStringStartsWith('Biggest lead:', trim($line->text()));
        self::assertCount(2, $line->filter('select'));
        self::assertSame('p-' . PlayerFixture::PLAYER_WITH_STRIPE, $line->filter('#comparison-pair-a option[selected]')->attr('value'));
        self::assertGreaterThan(0, $crawler->filter('.cmp-ring-a')->count());
        self::assertGreaterThan(0, $crawler->filter('.cmp-ring-b')->count());

        $crawler = $component->call('changeTab', ['tab' => 'charts'])->render()->crawler();
        self::assertCount(0, $crawler->filter('[data-testid="comparison-pair-sort"]'));
        $charts = $crawler->filter('[data-testid="comparison-pair-charts"]');
        self::assertCount(1, $charts);
        self::assertStringStartsWith('Compare', trim($charts->text()));
        self::assertStringContainsString(' with ', $charts->text());

        // Two subjects: nothing to pick
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->mount($client, ['kind' => 'solo', 'sort' => 'lead'])->render()->crawler();
        self::assertCount(0, $crawler->filter('[data-testid^="comparison-pair-"]'));
    }

    public function testClearingTheLineUpAsksFirst(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $component = $this->mount($client, ['kind' => 'solo']);
        $crawler = $component->render()->crawler();
        self::assertCount(1, $crawler->filter('[data-testid="comparison-clear"]'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-clear-confirm"]'));

        $crawler = $component->call('askClear')->render()->crawler();
        self::assertSame('Remove all 2? Yes, clear Cancel', self::squash($crawler->filter('[data-testid="comparison-clear-confirm"]')->text()));
        self::assertSame(4, $this->lineUpSize(PlayerFixture::PLAYER_WITH_STRIPE), 'Asking removes nothing');

        // Cancel is a plain re-render: the question is gone, nothing changed
        $crawler = $component->refresh()->render()->crawler();
        self::assertCount(0, $crawler->filter('[data-testid="comparison-clear-confirm"]'));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-clear"]'));

        $crawler = $component->call('clear')->render()->crawler();
        self::assertSame(['You'], self::chipNames($crawler));
        self::assertCount(1, $crawler->filter('[data-testid="comparison-empty-line-up"]'));
        self::assertCount(0, $crawler->filter('[data-testid="comparison-clear"]'), 'Nobody left to clear');
        // Her own Solo row stays, the Pairs line-up is another line-up
        self::assertSame(2, $this->lineUpSize(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testNothingToClearInASharedComparison(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_ADMIN);

        $component = $this->mount($client, ['with' => self::STRIPE_REF . ',' . self::REGULAR_REF]);
        self::assertCount(0, $component->render()->crawler()->filter('[data-testid="comparison-clear"]'));

        // Even when called directly: a preview writes nothing
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO comparison_subject (id, player_id, subject_player_id, added_at) VALUES (gen_random_uuid(), :owner, :owner, NOW()), (gen_random_uuid(), :owner, :other, NOW())',
            ['owner' => PlayerFixture::PLAYER_ADMIN, 'other' => PlayerFixture::PLAYER_REGULAR],
        );
        $component->call('clear');
        self::assertSame(2, $this->lineUpSize(PlayerFixture::PLAYER_ADMIN));
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
     * A live action the way the browser sends it: the props of the last render + the models the browser re-sends on its
     * own (see modelsTheBrowserResends())
     */
    private function browserAction(KernelBrowser $client, Crawler $crawler, string $action): Crawler
    {
        $root = $crawler->filter('[data-testid="comparison"]');
        $props = json_decode((string) $root->attr('data-live-props-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($props);

        $client->request('POST', '/en/_components/Comparison/' . $action, [
            'data' => json_encode(['props' => $props, 'updated' => self::modelsTheBrowserResends($root, $props), 'args' => []], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseIsSuccessful();

        return new Crawler((string) $client->getResponse()->getContent(), 'http://localhost/');
    }

    /**
     * What live_controller.js (synchronizeValueOfModelFields) marks as changed after a render without anybody touching a
     * thing: it writes each prop into its non-multiple <select data-model> - `${value}`, so null is "null" - and reads the
     * select back; a value no option has leaves the browser on the first option, and anything !== the prop is re-sent.
     *
     * @param array<mixed> $props
     * @return array<string, string>
     */
    private static function modelsTheBrowserResends(Crawler $root, array $props): array
    {
        $updated = [];

        foreach ($root->filter('select[data-model]:not([multiple])') as $select) {
            assert($select instanceof \DOMElement);
            $directive = $select->getAttribute('data-model');
            $model = substr($directive, (int) strrpos('|' . $directive, '|'));
            $prop = $props[$model] ?? null;
            $written = match (true) {
                $prop === null => 'null',
                is_bool($prop) => $prop ? 'true' : 'false',
                is_scalar($prop) => (string) $prop,
                default => '',
            };
            $options = (new Crawler($select))->filter('option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
            $value = in_array($written, $options, true) ? $written : ($options[0] ?? '');

            if ($value !== $prop) {
                $updated[$model] = $value;
            }
        }

        return $updated;
    }

    /**
     * Times both solved, one puzzle each - a list long enough to page through
     */
    private function seedSharedPuzzles(string $playerA, string $playerB, int $count): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            <<<SQL
WITH puzzles AS (
    INSERT INTO puzzle (id, pieces_count, name, approved, is_available)
    SELECT gen_random_uuid(), 500 + g, 'Shared ' || g, true, true FROM generate_series(1, :count) AS g
    RETURNING id
),
solvers (player_id, seconds) AS (VALUES (CAST(:a AS UUID), 1800), (CAST(:b AS UUID), 1900))
INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, suspicious, puzzling_type, puzzlers_count)
SELECT gen_random_uuid(), solvers.player_id, puzzles.id, solvers.seconds, NOW() - INTERVAL '1 day', NOW() - INTERVAL '1 day', true, true, false, 'solo', 1
FROM puzzles CROSS JOIN solvers
SQL,
            ['count' => $count, 'a' => $playerA, 'b' => $playerB],
            ['count' => ParameterType::INTEGER],
        );
    }

    /**
     * "163 puzzles · Solved by both" → 163
     */
    private static function listedTotal(Crawler $crawler): int
    {
        self::assertSame(1, preg_match('/^(\d+) puzzles/', trim($crawler->filter('[data-testid="comparison-total"]')->text()), $match));

        return (int) $match[1];
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
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
