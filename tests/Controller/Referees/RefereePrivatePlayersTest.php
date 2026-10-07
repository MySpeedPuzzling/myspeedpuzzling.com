<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Referees;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A referee (docs/features/competitions-management/live-results.md "Referees") never sees the #code, profile name or id
 * of a player who is private to them - PrivateProfileAccess decides, like everywhere: not in the round's state, not in
 * the live entry page, not in their own saves' answers, not among the event's people for the quick add, not on their
 * live updates (a topic of their own, which withholds every private player). Organisers keep seeing everything as
 * recorded. Gina (PLAYER_PRIVATE) is private and lets PLAYER_WITH_FAVORITES - the referee here - see her.
 */
final class RefereePrivatePlayersTest extends WebTestCase
{
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string REFEREE = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string GROUP_B = OfficialResultsFixture::ROUND_GROUP_B;
    private const string GINA = 'participant_round:' . OfficialResultsFixture::ENTRY_B_GINA;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();

        self::getContainer()->get(Connection::class)->insert('competition_referee', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'player_id' => self::REFEREE,
            'added_by_id' => self::ORGANISER,
            'added_at' => '2026-10-01 10:00:00',
        ]);
    }

    public function testARefereeDoesNotSeeAPrivatePlayerWhoDoesNotLetThem(): void
    {
        $this->leaveGinasAllowList();
        TestingLogin::asPlayer($this->browser, self::REFEREE);

        $gina = $this->entryOf($this->state(), self::GINA);
        self::assertNull($gina['playerCode']);
        self::assertNull($gina['playerId']);
        self::assertNull($gina['playerName']);
        self::assertTrue($gina['playerWithheld']);
        // The organiser's record stays: the referee finds her by the name on the list
        self::assertSame('Gina Quick', $gina['name']);

        $crawler = $this->browser->request('GET', '/en/live-results/' . self::GROUP_B);
        self::assertResponseIsSuccessful();
        self::assertNull($this->entryOf($this->initialState($crawler), self::GINA)['playerCode']);
        self::assertStringNotContainsStringIgnoringCase('player2', (string) $this->browser->getResponse()->getContent());

        // ... nor in the quick add's people of the event (Gina is no entry of Group A)
        $crawler = $this->browser->request('GET', '/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertSame([null], array_column(array_filter($this->eventPeople($crawler), static fn (array $person): bool => $person['name'] === 'Gina Quick'), 'playerCode'));

        // ... nor in the answer to the referee's own save
        $this->post('/en/official-results/rounds/' . self::GROUP_B . '/changes', ['changes' => [[
            'clientChangeId' => Uuid::uuid7()->toString(), 'entry' => self::GINA, 'field' => 'result', 'from' => ['seconds' => 3900], 'to' => ['seconds' => 3910],
        ]]]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->entryOf($this->json(), self::GINA)['playerCode']);
    }

    public function testARefereeOnTheAllowListSeesHerAndOrganisersAlwaysDo(): void
    {
        TestingLogin::asPlayer($this->browser, self::REFEREE);
        $gina = $this->entryOf($this->state(), self::GINA);
        self::assertSame('player2', $gina['playerCode']);
        self::assertArrayNotHasKey('playerWithheld', $gina);

        $this->leaveGinasAllowList();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $gina = $this->entryOf($this->state(), self::GINA);
        self::assertSame('player2', $gina['playerCode']);
        self::assertArrayNotHasKey('playerWithheld', $gina);

        $crawler = $this->browser->request('GET', '/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertSame(['PLAYER2'], array_column(array_filter($this->eventPeople($crawler), static fn (array $person): bool => $person['name'] === 'Gina Quick'), 'playerCode'));
    }

    public function testTheRefereesLiveUpdatesWithholdEveryPrivatePlayer(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $this->browser->disableReboot();

        $this->post('/en/official-results/rounds/' . self::GROUP_B . '/changes', ['changes' => [[
            'clientChangeId' => Uuid::uuid7()->toString(), 'entry' => self::GINA, 'field' => 'result', 'from' => ['seconds' => 3900], 'to' => ['seconds' => 3920],
        ]]]);
        self::assertResponseIsSuccessful();

        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);
        $updates = $hub->getPublishedUpdates();
        self::assertCount(2, $updates);

        self::assertSame(['/round-results/' . self::GROUP_B], $updates[0]->getTopics());
        self::assertSame('player2', $this->entryOf(self::decode($updates[0]->getData()), self::GINA)['playerCode']);

        // No viewer on an update - nobody's allow list counts there; the live entry keeps what its state showed
        self::assertSame(['/round-results/' . self::GROUP_B . '/referees'], $updates[1]->getTopics());
        self::assertTrue($updates[1]->isPrivate());
        $gina = $this->entryOf(self::decode($updates[1]->getData()), self::GINA);
        self::assertNull($gina['playerCode']);
        self::assertTrue($gina['playerWithheld']);
        self::assertSame(['seconds' => 3920], $gina['result']);
    }

    private function leaveGinasAllowList(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'DELETE FROM private_profile_viewer WHERE owner_id = :gina AND viewer_id = :referee',
            ['gina' => PlayerFixture::PLAYER_PRIVATE, 'referee' => self::REFEREE],
        );
    }

    /**
     * @return array<mixed>
     */
    private function state(): array
    {
        $this->browser->request('GET', '/en/official-results/rounds/' . self::GROUP_B);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @return array<mixed>
     */
    private function initialState(Crawler $crawler): array
    {
        return self::decode($crawler->filter('script[data-live-results-target="initialState"]')->text());
    }

    /**
     * @return list<array{name: string, playerCode: null|string}>
     */
    private function eventPeople(Crawler $crawler): array
    {
        /** @var list<array{name: string, playerCode: null|string}> $people */
        $people = self::decode($crawler->filter('script[data-live-results-target="eventPeople"]')->text());

        return $people;
    }

    /**
     * @param array<mixed> $state
     * @return array<string, mixed>
     */
    private function entryOf(array $state, string $ref): array
    {
        self::assertIsArray($state['entries'] ?? null);

        foreach ($state['entries'] as $entry) {
            if (is_array($entry) && ($entry['ref'] ?? null) === $ref) {
                /** @var array<string, mixed> $entry */
                return $entry;
            }
        }

        self::fail('No entry ' . $ref);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $url, array $body): void
    {
        $this->browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        return self::decode((string) $this->browser->getResponse()->getContent());
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
