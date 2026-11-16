<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\ResultsImportScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/event-results-import/README.md D10, rendered: a pair at an imported teams event is a row of the round
 * page like every team - its place, its name, "2 puzzlers" and "This was my team" for a signed-in visitor. Made-up event
 * and teams.
 */
final class RoundResultsImportedPairTest extends WebTestCase
{
    use ResultsImportScenario;

    public function testThePairIsRankedOnTheTeamsRoundPage(): void
    {
        $browser = self::createClient();
        $container = self::getContainer();
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;

        self::applyTab($container, $this->createResultsSource($container), '401', 'Pair Night', self::sheetCsv([
            "First Team,0:40:00,4,,Pair Night,Hall,\"Springfield, MN\",10/1/26,7:00 PM,90,Teams,$link",
            'Just Us Two,0:42:00,2,,,,,,,,,',
            'Second Team,0:45:00,3,,,,,,,,,',
        ]));

        $slugs = $container->get(Connection::class)->fetchAssociative(
            "SELECT c.slug AS event, cr.slug AS round FROM competition c JOIN competition_round cr ON cr.competition_id = c.id WHERE c.name = 'Pair Night'",
        );
        self::assertIsArray($slugs);
        self::assertIsString($slugs['event']);
        self::assertIsString($slugs['round']);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', sprintf('/en/events/%s/results/%s', $slugs['event'], $slugs['round']));

        $this->assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-round-result-status="finished"]');
        self::assertCount(3, $rows);

        $pair = $rows->eq(1);
        self::assertSame('2', trim($pair->filter('[data-round-result-rank]')->text()));
        self::assertStringContainsString('Just Us Two', $pair->filter('[data-testid="anonymous-team"]')->text());
        self::assertStringContainsString('2 puzzlers', $pair->filter('[data-testid="anonymous-team"]')->text());
        self::assertCount(1, $pair->filter('[data-testid="event-result-claim-button"]'));
        self::assertStringContainsString('3 puzzlers', $rows->eq(2)->text());
    }
}
