<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use SpeedPuzzling\Web\Services\EventResultsImport\GoogleSheetReader;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Registering an organizer's results sheet, reading what became of its tabs, syncing it at once
 * (docs/features/internal-api.md "Event results sheets"). Made-up spreadsheet ids and events.
 */
final class EventResultsSheetsInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    private const string SPREADSHEET_ID = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcd';

    public function testWithoutTokenEveryEndpointIsUnauthorized(): void
    {
        $browser = self::createClient();

        foreach ([['GET', '/internal-api/event-results-sheets'], ['POST', '/internal-api/event-results-sheets'], ['POST', '/internal-api/event-results-sheets/018d0000-0000-0000-0000-000000000001/sync']] as [$method, $uri]) {
            self::callInternalApi($browser, $method, $uri, [], token: null);
            self::assertResponseStatusCodeSame(401, $method . ' ' . $uri);
        }
    }

    public function testRegistersASheetFromItsLinkAndListsIt(): void
    {
        $browser = self::createClient();

        $created = self::callInternalApi($browser, 'POST', '/internal-api/event-results-sheets', [
            'spreadsheet' => 'https://docs.google.com/spreadsheets/d/' . self::SPREADSHEET_ID . '/edit?gid=0#gid=0',
            'organizationId' => OrganizationFixture::ORGANIZATION_RIVERBEND,
            'actingPlayerId' => PlayerFixture::PLAYER_WITH_STRIPE,
            'timezone' => 'America/Chicago',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($created['id'] ?? null);
        self::assertSame(self::SPREADSHEET_ID, $created['spreadsheetId']);
        self::assertSame('us', $created['countryCode'], 'The organization\'s country when none is given');
        self::assertSame([], $created['tabs']);

        $list = self::callInternalApi($browser, 'GET', '/internal-api/event-results-sheets');
        self::assertResponseIsSuccessful();
        self::assertIsArray($list['sheets'] ?? null);
        self::assertSame([$created['id']], array_column($list['sheets'], 'id'));
    }

    public function testRefusesWhatCannotWork(): void
    {
        $browser = self::createClient();
        $valid = [
            'spreadsheet' => self::SPREADSHEET_ID,
            'organizationId' => OrganizationFixture::ORGANIZATION_RIVERBEND,
            'actingPlayerId' => PlayerFixture::PLAYER_WITH_STRIPE,
            'timezone' => 'America/Chicago',
        ];

        // Invalid input is a 400 with the field's error, like every internal API endpoint
        $answer = self::callInternalApi($browser, 'POST', '/internal-api/event-results-sheets', ['timezone' => 'Mars/Olympus'] + $valid);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($answer['errors'] ?? null);
        self::assertArrayHasKey('timezone', $answer['errors']);

        self::callInternalApi($browser, 'POST', '/internal-api/event-results-sheets', ['spreadsheet' => 'not a sheet'] + $valid);
        self::assertResponseStatusCodeSame(400);

        // Somebody not on the organization's team cannot be credited with its events
        self::callInternalApi($browser, 'POST', '/internal-api/event-results-sheets', ['actingPlayerId' => PlayerFixture::PLAYER_REGULAR] + $valid);
        self::assertResponseStatusCodeSame(422);

        self::callInternalApi($browser, 'POST', '/internal-api/event-results-sheets', $valid);
        self::assertResponseStatusCodeSame(201);
        self::callInternalApi($browser, 'POST', '/internal-api/event-results-sheets', $valid);
        self::assertResponseStatusCodeSame(409, 'A sheet is registered once');
    }

    public function testSyncReadsTheSheetAtOnceAndAnswersWhatBecameOfItsTabs(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();

        $created = self::callInternalApi($browser, 'POST', '/internal-api/event-results-sheets', [
            'spreadsheet' => self::SPREADSHEET_ID,
            'organizationId' => OrganizationFixture::ORGANIZATION_RIVERBEND,
            'actingPlayerId' => PlayerFixture::PLAYER_WITH_STRIPE,
            'timezone' => 'America/Chicago',
        ]);
        self::assertIsString($created['id'] ?? null);

        $csv = "Name,Time,Team Size,Contest Name,Location,City and State,Date,Start time,Time limit,Competition Type,Link to MySpeedPuzzling\n"
            . 'Puzzle Pals,1:10:13,4,Maple Street Puzzle Night,Taproom,"Springfield, MN",10/5/26,7:00 PM,120,Teams,https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_02 . "\n";

        $browser->getContainer()->set(GoogleSheetReader::class, new GoogleSheetReader(new MockHttpClient(
            static fn (string $method, string $url): MockResponse => str_contains($url, 'htmlview')
                ? new MockResponse('<script>items.push({name: "Maple 10\/5", pageUrl: "x", gid: "11",initialSheet: false});</script>', ['response_headers' => ['content-type' => 'text/html']])
                : new MockResponse($csv, ['response_headers' => ['content-type' => 'text/csv']]),
        )));

        $synced = self::callInternalApi($browser, 'POST', '/internal-api/event-results-sheets/' . $created['id'] . '/sync');

        self::assertResponseIsSuccessful();
        self::assertNull($synced['lastFetchError']);
        self::assertIsArray($synced['tabs']);
        self::assertCount(1, $synced['tabs']);
        $tab = $synced['tabs'][0];
        self::assertIsArray($tab);
        self::assertSame('11', $tab['gid']);
        self::assertSame('imported', $tab['status']);
        self::assertIsString($tab['competitionId']);
        self::assertSame(1, $tab['results']);
    }
}
