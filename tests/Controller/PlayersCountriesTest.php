<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CountryCupPeriod;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Players page's country tiles and Country Cup (docs/features/players-page/README.md, stream S4), on seeded
 * community_scope_stats rows: thirteen countries, Germany the most active, Czechia (John Doe's country) 12th in the
 * Cup in both months, Slovakia below the Cup's minimum of active puzzlers.
 */
final class PlayersCountriesTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string MOST_ACTIVE = '#players-countries-most_active';
    private const string MOST_PUZZLERS = '#players-countries-most_puzzlers';
    private const string RISING = '#players-countries-rising';
    private const string VISIBLE_BOARD = '.players-cup-board:not([hidden])';

    public function testAGuestSeesTheTilesInThreeOrdersAndTheCup(): void
    {
        $browser = self::createClient();
        self::seedCountries();

        $crawler = $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();

        // Most active is shown, the other orders are rendered for the tabs but hidden
        self::assertNull($crawler->filter(self::MOST_ACTIVE)->attr('hidden'));
        self::assertNotNull($crawler->filter(self::MOST_PUZZLERS)->attr('hidden'));
        self::assertNotNull($crawler->filter(self::RISING)->attr('hidden'));
        self::assertSelectorExists('#players-countries-tab-most_active[aria-selected="true"]');
        self::assertSelectorExists('#players-countries-tab-rising[aria-selected="false"]');

        self::assertCount(12, $crawler->filter(self::MOST_ACTIVE . ' .players-tile'));
        self::assertSame('Germany', trim($crawler->filter(self::MOST_ACTIVE . ' .players-tile-country')->first()->text()));
        self::assertSame('/en/puzzlers?scope=de', $crawler->filter(self::MOST_ACTIVE . ' .players-tile')->first()->attr('href'));
        self::assertStringContainsString('active of 400', $crawler->filter(self::MOST_ACTIVE . ' .players-tile')->first()->text());
        self::assertSame('United States of America', trim($crawler->filter(self::MOST_PUZZLERS . ' .players-tile-country')->first()->text()));

        // Rising: Slovakia doubled its solves, but from 5 - Austria (+50 %) leads
        $rising = $crawler->filter(self::RISING . ' .players-tile');
        self::assertSame('Austria', trim($rising->first()->filter('.players-tile-country')->text()));
        self::assertStringContainsString('+50%', $rising->first()->text());
        self::assertStringNotContainsString('Slovakia', $crawler->filter(self::RISING)->text());

        self::assertSelectorTextContains('.players-countries-all summary', 'All 13 countries');
        self::assertCount(13, $crawler->filter('.players-countries-table tbody tr'));

        // One Cup board is shown: per active puzzler, nobody's country highlighted
        $board = $crawler->filter(self::VISIBLE_BOARD);
        self::assertCount(1, $board);
        self::assertSame('per_puzzler', $board->attr('data-tab-measure'));
        self::assertCount(10, $board->filter('tbody:not(.players-cup-marked) .players-cup-row'));
        self::assertSame('Germany', trim($board->filter('.players-cup-country-name')->first()->text()));
        self::assertCount(0, $crawler->filter('.players-cup-row.is-viewer'));
        self::assertSelectorTextContains('.players-cup-note', 'Sign in and log a puzzle to add your pieces to your country.');
        self::assertSelectorTextContains('.players-cup-note', 'Countries with at least 10 active puzzlers.');
    }

    public function testThePeriodShownFirstFollowsTheClock(): void
    {
        $browser = self::createClient();
        self::seedCountries();

        $crawler = $browser->request('GET', '/en/puzzlers');

        $expected = CountryCupPeriod::defaultAt(self::getContainer()->get(ClockInterface::class)->now());
        self::assertSame($expected->value, $crawler->filter(self::VISIBLE_BOARD)->attr('data-tab-period'));
        self::assertSelectorExists('#players-cup-tab-' . $expected->value . '[aria-selected="true"]');
        self::assertSelectorExists('#players-cup-tab-per_puzzler[aria-selected="true"]');
        self::assertSelectorExists('#players-cup-tab-total[aria-selected="false"]');
        // Four boards: two measures in two months
        self::assertCount(4, $crawler->filter('.players-cup-board'));
    }

    public function testASignedInPlayersCountryIsAddedBelowTheLeadersWithItsPosition(): void
    {
        $browser = self::createClient();
        self::seedCountries();
        // John Doe lives in Czechia
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();

        $board = $crawler->filter(self::VISIBLE_BOARD);
        self::assertCount(0, $board->filter('tbody:not(.players-cup-marked) .players-cup-row.is-viewer'));

        $viewerRow = $board->filter('.players-cup-marked .players-cup-row.is-viewer');
        self::assertCount(1, $viewerRow);
        self::assertSame('12', trim($viewerRow->filter('.players-cup-position')->text()));
        self::assertStringContainsString('Czechia', $viewerRow->text());

        self::assertSelectorTextContains('.players-cup-note', 'Every puzzle you log adds its pieces to Czechia.');
    }

    public function testACountryBelowTheMinimumIsShownWithoutAPosition(): void
    {
        $browser = self::createClient();
        self::seedCountries();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        self::database()->executeStatement("UPDATE player SET country = 'sk' WHERE id = :id", ['id' => PlayerFixture::PLAYER_REGULAR]);

        $crawler = $browser->request('GET', '/en/puzzlers');

        $viewerRow = $crawler->filter(self::VISIBLE_BOARD . ' .players-cup-marked .players-cup-row.is-viewer');
        self::assertCount(1, $viewerRow);
        self::assertSame('–', trim($viewerRow->filter('.players-cup-position')->text()));
        self::assertStringContainsString('3 active · ranked from 10', $viewerRow->text());
    }

    public function testASignedInPlayerWithoutACountryIsInvitedToAddOne(): void
    {
        $browser = self::createClient();
        self::seedCountries();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        self::database()->executeStatement('UPDATE player SET country = NULL WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);

        $crawler = $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-cup-row.is-viewer'));
        self::assertSelectorTextContains('.players-cup-note a', 'Add your country and your pieces count for it.');
    }

    public function testTheScopesCountryIsMarkedInTheTilesAndTheCup(): void
    {
        $browser = self::createClient();
        self::seedCountries();

        $crawler = $browser->request('GET', '/en/puzzlers?scope=gb');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(self::MOST_ACTIVE . ' .players-tile.is-current[href="/en/puzzlers?scope=gb"][aria-current="true"]');
        self::assertCount(1, $crawler->filter(self::MOST_ACTIVE . ' .players-tile.is-current'));
        self::assertSelectorExists('.players-countries-table tr.is-current');

        // Great Britain is 11th in the Cup - added below the leaders, marked as the scope's country
        $scopeRow = $crawler->filter(self::VISIBLE_BOARD . ' .players-cup-marked .players-cup-row.is-scope');
        self::assertCount(1, $scopeRow);
        self::assertSame('11', trim($scopeRow->filter('.players-cup-position')->text()));
    }

    public function testTheTilesTheCupAndTheScopeSwitchReadTheCountriesOnce(): void
    {
        $browser = self::createClient();
        self::seedCountries();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/puzzlers');
        self::assertResponseIsSuccessful();

        $countriesStatements = array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => str_contains($sql, 'FROM community_scope_stats') && str_contains($sql, "scope <> 'world'"),
        );

        self::assertCount(1, $countriesStatements);
    }

    public function testNoCountriesYetRendersNeitherTheTilesNorTheCup(): void
    {
        $browser = self::createClient();
        self::database()->executeStatement('DELETE FROM community_scope_stats');

        $crawler = $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-countries'));
        self::assertCount(0, $crawler->filter('.players-cup'));
    }

    public function testACupWithoutACountryAtTheMinimumSaysSo(): void
    {
        $browser = self::createClient();
        $database = self::database();
        $database->executeStatement('DELETE FROM community_scope_stats');
        self::insertCountry($database, 'sk', [
            'registered_players' => 30,
            'active30d' => 3,
            'solves30d' => 10,
            'solves_prev30d' => 5,
            'active_this_month' => 3,
            'pieces_this_month' => 9_000,
            'active_last_month' => 3,
            'pieces_last_month' => 9_000,
        ]);

        $crawler = $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(self::VISIBLE_BOARD . ' .players-cup-empty', 'No country with 10 active puzzlers in');
        // In total pieces Slovakia leads
        self::assertStringContainsString('Slovakia', $crawler->filter('.players-cup-board[data-tab-measure="total"]')->first()->text());
    }

    private static function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    /**
     * Thirteen countries. Per active puzzler (and in total) both months: Germany 1st ... Great Britain 11th, Czechia
     * 12th; Slovakia has 3 active puzzlers, below the Cup's minimum.
     */
    private static function seedCountries(): void
    {
        $database = self::database();
        $database->executeStatement('DELETE FROM community_scope_stats');

        $cupOrder = ['de', 'us', 'fr', 'es', 'it', 'pl', 'at', 'nl', 'be', 'dk', 'gb', 'cz'];

        foreach ($cupOrder as $index => $code) {
            $active = 20;
            $pieces = (30 - $index) * 1000 * $active;

            self::insertCountry($database, $code, [
                // The United States have the most registered players, Germany the most active
                'registered_players' => $code === 'us' ? 900 : 400 - $index * 10,
                'active30d' => 100 - $index * 5,
                'solves30d' => $code === 'at' ? 150 : 100,
                'solves_prev30d' => 100,
                'active_this_month' => $active,
                'pieces_this_month' => $pieces,
                'active_last_month' => $active,
                'pieces_last_month' => $pieces,
            ]);
        }

        self::insertCountry($database, 'sk', [
            'registered_players' => 30,
            'active30d' => 3,
            'solves30d' => 10,
            'solves_prev30d' => 5,
            'active_this_month' => 3,
            'pieces_this_month' => 90_000,
            'active_last_month' => 3,
            'pieces_last_month' => 90_000,
        ]);
    }

    /**
     * @param array<string, int> $numbers
     */
    private static function insertCountry(Connection $database, string $code, array $numbers): void
    {
        $database->insert('community_scope_stats', $numbers + [
            'scope' => $code,
            'median_best500_seconds' => null,
            'puzzlers_with500' => 0,
            'monthly_solves' => '[0,0,0,0,0,0,0,0,0,0,0,0]',
            'new_faces14d' => 0,
            'computed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
