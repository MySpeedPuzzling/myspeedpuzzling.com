<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OfficialResults;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Every official results JSON endpoint answers a round or an event that is unknown or was deleted with its own JSON 404
 * (OfficialResultsApiNotFoundSubscriber) - the pages' client reads it as "gone", never as "the server is busy, retry".
 */
final class OfficialResultsNotFoundTest extends WebTestCase
{
    private const string GONE = '018d0020-0000-0000-0000-000000009999';

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideEndpoints(): iterable
    {
        $round = '/en/official-results/rounds/' . self::GONE;
        $competition = '/en/official-results/competitions/' . self::GONE;

        yield 'round state' => ['GET', $round, 'round_not_found'];
        yield 'result changes' => ['POST', $round . '/changes', 'round_not_found'];
        yield 'table numbers' => ['POST', $round . '/table-numbers', 'round_not_found'];
        yield 'table numbers usage' => ['POST', $round . '/table-numbers-usage', 'round_not_found'];
        yield 'seating proposal' => ['GET', $round . '/seating-proposal', 'round_not_found'];
        yield 'take out' => ['POST', $round . '/take-out', 'round_not_found'];
        yield 'publish' => ['POST', $round . '/publish', 'round_not_found'];
        yield 'unpublish' => ['POST', $round . '/unpublish', 'round_not_found'];
        yield 'competition state' => ['GET', $competition, 'competition_not_found'];
        yield 'advance' => ['POST', $competition . '/advance', 'competition_not_found'];
    }

    #[DataProvider('provideEndpoints')]
    public function testAnUnknownOrDeletedRoundOrEventIsAJson404(string $method, string $url, string $error): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame(
            [
                'error' => $error,
                'message' => $error === 'round_not_found' ? 'This round does not exist any more.' : 'This event does not exist any more.',
            ],
            json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testADeletedRoundIsGoneForADeviceThatStillHasItOpen(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        // The Pairs Final has nothing official in it - its organiser deletes it while a page still shows it
        $browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_PAIRS_FINAL);
        self::assertResponseIsSuccessful();

        self::getContainer()->get(Connection::class)->executeStatement(
            'DELETE FROM competition_round WHERE id = :id',
            ['id' => OfficialResultsFixture::ROUND_PAIRS_FINAL],
        );

        $browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_PAIRS_FINAL);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testEveryPageCarriesItsTranslatedGoneMessage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertStringContainsString('This round does not exist any more', $crawler->filter('[data-banner="gone"]')->text());
        $texts = json_decode((string) $crawler->filter('[data-controller="results-desk"]')->attr('data-results-desk-texts-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);
        self::assertSame('Round deleted', $texts['pill_gone']);

        $crawler = $browser->request('GET', '/en/round-seating/' . OfficialResultsFixture::ROUND_GROUP_A);
        $gone = $crawler->filter('[data-round-seating-target="gone"]');
        self::assertNotNull($gone->attr('hidden'));
        self::assertStringContainsString('This round does not exist any more', $gone->text());

        $crawler = $browser->request('GET', '/en/manage-event-results/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        $gone = $crawler->filter('[data-results-overview-gone]');
        self::assertNotNull($gone->attr('hidden'));
        self::assertSame('This event does not exist any more.', trim($gone->text()));

        $crawler = $browser->request('GET', '/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertStringContainsString('This round does not exist any more', (string) $crawler->filter('[data-controller="live-results"]')->attr('data-live-results-messages-value'));
    }

    public function testThePagesStayPagesWithTheirOwn404(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/manage-round-results/' . self::GONE);

        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith('text/html', (string) $browser->getResponse()->headers->get('Content-Type'));
    }
}
