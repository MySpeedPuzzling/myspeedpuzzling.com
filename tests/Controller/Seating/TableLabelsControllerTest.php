<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Seating;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\Controller\InternalApi\InternalApiRequests;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Table names (table-labels-and-plates.md): the seating page's "Table names" panel, its endpoint
 * (`official_results_table_labels` - OfficialResultsApi's rules, organisers only), the internal API, and what a
 * labelled round shows on its pages. The Results Cup's Pairs round seats Puzzle Sharks at 1, Corner Pieces 2, Edge
 * Hunters 3.
 */
final class TableLabelsControllerTest extends WebTestCase
{
    use InternalApiRequests;

    private const string URL = '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_PAIRS . '/table-labels';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testOnlyTheEventsOrganisersSaveNames(): void
    {
        $this->post(['rounds' => [OfficialResultsFixture::ROUND_PAIRS], 'labels' => ['A1', 'A2', 'A3'], 'expected' => (object) []]);
        self::assertResponseStatusCodeSame(401);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->post(['rounds' => [OfficialResultsFixture::ROUND_PAIRS], 'labels' => ['A1', 'A2', 'A3'], 'expected' => (object) []]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->stored(OfficialResultsFixture::ROUND_PAIRS));
    }

    public function testTheSeatingPageOffersNamesAndShowsThemOnceSaved(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $page = $this->browser->request('GET', '/en/round-seating/' . OfficialResultsFixture::ROUND_PAIRS);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-seating-labels]'));
        // "Use for": the other pairs round
        self::assertCount(1, $page->filter('[data-table-labels-editor-target="round"][value="' . OfficialResultsFixture::ROUND_PAIRS_FINAL . '"]'));
        // Plain numbers: the first table input is a number as before
        self::assertCount(1, $page->filter('input[type="number"].seating-first-input'));

        $this->post([
            'rounds' => [OfficialResultsFixture::ROUND_PAIRS, OfficialResultsFixture::ROUND_PAIRS_FINAL],
            'labels' => ['A1 L', 'A1 R', 'A2 L', 'A2 R'],
            'expected' => [OfficialResultsFixture::ROUND_PAIRS => null, OfficialResultsFixture::ROUND_PAIRS_FINAL => null],
        ]);
        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertSame([OfficialResultsFixture::ROUND_PAIRS, OfficialResultsFixture::ROUND_PAIRS_FINAL], $answer['changed']);
        self::assertSame(['A1 L', 'A1 R', 'A2 L', 'A2 R'], $this->stored(OfficialResultsFixture::ROUND_PAIRS_FINAL));

        $page = $this->browser->request('GET', '/en/round-seating/' . OfficialResultsFixture::ROUND_PAIRS);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('4 tables: A1 L … A2 R', $page->filter('[data-seating-labels] summary')->text());
        // The round state the page draws from carries the names; the first table is a name
        self::assertStringContainsString('&quot;tableLabels&quot;:[&quot;A1 L&quot;', (string) $this->browser->getResponse()->getContent());
        self::assertSame('A1 L', $page->filter('.seating-first-input')->attr('value'));

        // The printed lists name the tables
        $this->browser->request('GET', '/en/print-round-seating/' . OfficialResultsFixture::ROUND_PAIRS . '?list=tables');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('A2 L', (string) $this->browser->getResponse()->getContent());
    }

    public function testRefusalsAnswerWithTheirMessages(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Edge Hunters sit at 3
        $this->post(['rounds' => [OfficialResultsFixture::ROUND_PAIRS], 'labels' => ['A1', 'A2'], 'expected' => [OfficialResultsFixture::ROUND_PAIRS => null]]);
        self::assertResponseStatusCodeSame(422);
        $answer = $this->json();
        self::assertSame('labels_refused', $answer['error']);
        self::assertIsArray($answer['problems']);
        self::assertIsArray($answer['problems'][0]);
        self::assertSame('too_few', $answer['problems'][0]['reason']);
        self::assertIsString($answer['problems'][0]['message']);
        self::assertStringContainsString('3', $answer['problems'][0]['message']);

        $this->post(['rounds' => [OfficialResultsFixture::ROUND_PAIRS], 'labels' => ['A1', 'a 1', 'A3'], 'expected' => [OfficialResultsFixture::ROUND_PAIRS => null]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_labels', $this->json()['error']);

        // This round must be one of the rounds
        $this->post(['rounds' => [OfficialResultsFixture::ROUND_PAIRS_FINAL], 'labels' => ['A1'], 'expected' => (object) []]);
        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->stored(OfficialResultsFixture::ROUND_PAIRS_FINAL));
    }

    public function testTheInternalApiGeneratesAHallPlan(): void
    {
        $answer = self::callInternalApi($this->browser, 'PUT', '/internal-api/rounds/' . OfficialResultsFixture::ROUND_PAIRS . '/table-labels', [
            'generate' => ['letters' => 'A-H', 'numbers' => '1-22', 'seats' => 2, 'seatStyle' => 'side', 'order' => 'letters', 'skip' => 'H22'],
            'alsoRounds' => [OfficialResultsFixture::ROUND_PAIRS_FINAL],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(350, $answer['count']);

        $stored = $this->stored(OfficialResultsFixture::ROUND_PAIRS_FINAL);
        self::assertIsArray($stored);
        self::assertSame(['A1 L', 'A1 R', 'A2 L'], array_slice($stored, 0, 3));

        $read = self::callInternalApi($this->browser, 'GET', '/internal-api/rounds/' . OfficialResultsFixture::ROUND_PAIRS . '/table-labels');
        self::assertIsArray($read['labels']);
        self::assertCount(350, $read['labels']);

        // Back to numbers
        self::callInternalApi($this->browser, 'PUT', '/internal-api/rounds/' . OfficialResultsFixture::ROUND_PAIRS . '/table-labels', ['labels' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->stored(OfficialResultsFixture::ROUND_PAIRS));

        self::callInternalApi($this->browser, 'PUT', '/internal-api/rounds/' . OfficialResultsFixture::ROUND_PAIRS . '/table-labels', ['labels' => ['A1']]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): void
    {
        $this->browser->request('POST', self::URL, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return null|list<string>
     */
    private function stored(string $roundId): null|array
    {
        $value = self::getContainer()->get(Connection::class)->fetchOne('SELECT table_labels FROM competition_round WHERE id = :id', ['id' => $roundId]);

        if (!is_string($value)) {
            return null;
        }

        /** @var list<string> $labels */
        $labels = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return $labels;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $data = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
