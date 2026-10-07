<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Seating;

use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The printable seating lists (docs/features/competitions-management/seating.md).
 */
final class RoundSeatingPrintControllerTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testOnlyTheEventsOrganisersMayPrint(): void
    {
        $this->browser->request('GET', '/en/print-round-seating/' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertResponseRedirects();

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', '/en/print-round-seating/' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertResponseStatusCodeSame(403);
    }

    public function testByTableThenEveryNameWithItsTable(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', '/en/print-round-seating/' . OfficialResultsFixture::ROUND_GROUP_A);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));

        // By table: 1..5, then Filip without a table
        $rows = $crawler->filter('#seating-by-table ~ table tbody tr');
        self::assertSame(
            ['1 Anna Fast CZ', '2 Ben Steady DE', '3 Cara Tied US', '4 Dan Unfinished CZ', '5 Eva Noshow SK', '– Filip Pending CZ'],
            $rows->each(static fn ($row): string => (string) preg_replace('/\s+/', ' ', trim($row->text()))),
        );
        self::assertStringContainsString('Not seated yet - ask at the desk.', $crawler->filter('body')->text());

        self::assertSame(
            ['Anna Fast 1', 'Ben Steady 2', 'Cara Tied 3', 'Dan Unfinished 4', 'Eva Noshow 5', 'Filip Pending –'],
            $crawler->filter('.name-row')->each(static fn ($row): string => (string) preg_replace('/\s+/', ' ', trim($row->text()))),
        );
    }

    public function testEveryMemberOfAPairPointsToThePairsTable(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', '/en/print-round-seating/' . OfficialResultsFixture::ROUND_PAIRS . '?list=names');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#seating-by-table'));
        self::assertSame([
            'Anna Fast Puzzle Sharks 1',
            'Ben Steady Puzzle Sharks 1',
            'Cara Tied Corner Pieces 2',
            'Dan Unfinished Corner Pieces 2',
            'Eva Noshow with Filip Pending –',
            'Filip Pending with Eva Noshow –',
            'Gina Quick Edge Hunters 3',
            'Hugo Slow Edge Hunters 3',
        ], $crawler->filter('.name-row')->each(static fn ($row): string => (string) preg_replace('/\s+/', ' ', trim($row->text()))));
    }

    public function testTheTablesListAlone(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', '/en/print-round-seating/' . OfficialResultsFixture::ROUND_PAIRS . '?list=tables');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.name-row'));
        self::assertStringContainsString('Anna Fast, Ben Steady', $crawler->filter('#seating-by-table ~ table')->text());
    }
}
