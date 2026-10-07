<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OfficialResults;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The official results JSON endpoints (docs/features/competitions-management/official-results.md): who may call them,
 * what they refuse, and their answers.
 */
final class OfficialResultsApiTest extends WebTestCase
{
    private const string STATE = '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A;
    private const string CHANGES = '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/changes';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testNobodySignedInGets401InsteadOfTheLoginPage(): void
    {
        $this->browser->request('GET', self::STATE);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'sign_in_required'], $this->json());

        $this->post(self::CHANGES, ['changes' => []]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testOnlyTheEventsOrganisersMayRead(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', self::STATE);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $this->json());
    }

    public function testTheStateHasTheRoundEveryRoundAndTheRankedEntries(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', self::STATE);

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $this->browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);

        $state = $this->json();
        self::assertSame('/round-results/' . OfficialResultsFixture::ROUND_GROUP_A, $state['topic']);
        self::assertIsArray($state['competition']);
        self::assertSame('Results Cup', $state['competition']['name']);
        self::assertIsArray($state['round']);
        self::assertSame(1000, $state['round']['piecesCount']);
        self::assertSame(['total' => 6, 'withTableNumber' => 5, 'withResult' => 5, 'qualified' => 2], $state['round']['entries']);
        self::assertTrue($state['round']['resultsPublished']);
        self::assertIsArray($state['rounds']);
        self::assertSame(['Group A', 'Group B', 'Pairs', 'Final', 'Pairs Final'], array_column($state['rounds'], 'name'));

        self::assertIsArray($state['entries']);
        self::assertSame(
            ['Anna Fast', 'Ben Steady', 'Cara Tied', 'Dan Unfinished', 'Eva Noshow', 'Filip Pending'],
            array_column($state['entries'], 'name'),
        );
        self::assertSame([1, 2, 2, 4, null, null], array_column($state['entries'], 'rank'));

        $anna = $state['entries'][0];
        self::assertIsArray($anna);
        self::assertSame('participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA, $anna['ref']);
        self::assertSame(['seconds' => 3600], $anna['result']);
        self::assertTrue($anna['qualified']);
        self::assertSame(1, $anna['tableNumber']);
        self::assertSame('cz', $anna['country']);
        self::assertSame(['playerId' => PlayerFixture::PLAYER_WITH_STRIPE, 'name' => 'Sarah Williams'], $anna['enteredBy']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $anna['playerId']);
    }

    public function testAPairRoundListsItsMembersAndTheirCountries(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_PAIRS);

        $state = $this->json();
        self::assertIsArray($state['entries']);
        $sharks = $state['entries'][0];
        self::assertIsArray($sharks);
        self::assertSame('team', $sharks['kind']);
        self::assertSame('Puzzle Sharks', $sharks['name']);
        self::assertSame(['cz', 'de'], $sharks['countries']);
        self::assertIsArray($sharks['members']);
        self::assertSame(['Anna Fast', 'Ben Steady'], array_column($sharks['members'], 'name'));

        $unnamed = array_values(array_filter($state['entries'], static fn (mixed $entry): bool => is_array($entry) && $entry['name'] === null));
        self::assertCount(1, $unnamed);
        self::assertSame('Eva Noshow, Filip Pending', $unnamed[0]['displayName']);
    }

    public function testWritesNeedJson(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('POST', self::CHANGES, ['changes' => '[]'], server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => 'csrf-token']);

        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'json_required'], $this->json());
    }

    public function testWritesFromAnotherSiteAreRefused(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post(self::CHANGES, ['changes' => [self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'qualified', false, true)]], origin: 'https://evil.example');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'invalid_csrf_token'], $this->json());
        $this->assertFilipNotQualified();
    }

    public function testAChangeSetIsAnsweredPerChangeWithTheEntriesAsTheyAreNow(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post(self::CHANGES, ['changes' => [
            self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'result', null, ['seconds' => 4800]),
            self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA, 'result', null, ['seconds' => 1]),
            // An entry of another round of the same event, addressed through this round
            self::change('participant_round:' . OfficialResultsFixture::ENTRY_B_GINA, 'qualified', true, false),
        ]]);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertFalse($answer['dryRun']);
        self::assertIsArray($answer['outcomes']);
        self::assertSame(['applied', 'conflict', 'rejected'], array_column($answer['outcomes'], 'status'));
        self::assertSame([null, 'changed_meanwhile', 'entry_not_found'], array_column($answer['outcomes'], 'reason'));
        $conflict = $answer['outcomes'][1];
        self::assertIsArray($conflict);
        self::assertSame('Somebody else changed this meanwhile.', $conflict['message']);
        self::assertSame(['seconds' => 3600], $conflict['current']);

        self::assertIsArray($answer['entries']);
        self::assertSame(['Anna Fast', 'Filip Pending'], array_column($answer['entries'], 'name'));
        self::assertSame([1, 4], array_column($answer['entries'], 'rank'));

        $database = self::getContainer()->get(Connection::class);
        self::assertSame(4800, $database->fetchOne('SELECT result_seconds FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_A_FILIP]));
        self::assertNotNull($database->fetchOne('SELECT qualified_at FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_B_GINA]));
    }

    public function testTheOtherDevicesLearnAboutTheChangeOnTheRoundsPrivateTopic(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->browser->disableReboot();

        $this->post(self::CHANGES, ['changes' => [self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'table_number', null, 6)]]);

        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertSame(['/round-results/' . OfficialResultsFixture::ROUND_GROUP_A], $updates[0]->getTopics());
        self::assertTrue($updates[0]->isPrivate());

        $payload = json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('official_results.entries', $payload['type']);
        self::assertIsArray($payload['entries']);
        self::assertSame([6], array_column($payload['entries'], 'tableNumber'));
    }

    public function testNothingIsBroadcastWhenNothingChanged(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->browser->disableReboot();

        $this->post(self::CHANGES, ['changes' => [self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA, 'qualified', true, true)]]);

        self::assertSame([], self::hub()->getPublishedUpdates());
    }

    public function testAnOrganiserOfAnotherEventCanNotWriteHere(): void
    {
        // PLAYER_WITH_STRIPE organises the Results Cup, not WJPC
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post('/en/official-results/rounds/' . CompetitionRoundFixture::ROUND_WJPC_FINAL . '/changes', ['changes' => []]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnreadableChangeSetIs400(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post(self::CHANGES, ['changes' => [['field' => 'qualified']]]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_changes', $this->json()['error']);
        // The referee reads the translated reason - never the parser's developer text
        self::assertSame('changes_unreadable', $this->json()['reason']);
        self::assertSame('These changes could not be read - enter them again.', $this->json()['message']);
    }

    public function testChangesForARoundThatIsGoneAreRefusedAsJson(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post('/en/official-results/rounds/018d0020-0000-0000-0000-00000000dead/changes', ['changes' => []]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('round_not_found', $this->json()['error']);
        self::assertSame('This round does not exist any more.', $this->json()['message']);
    }

    public function testPublishAndUnpublish(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post('/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_B . '/publish', []);
        self::assertResponseIsSuccessful();
        $round = $this->json()['round'];
        self::assertIsArray($round);
        self::assertTrue($round['resultsPublished']);

        $this->post('/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_B . '/unpublish', []);
        $round = $this->json()['round'];
        self::assertIsArray($round);
        self::assertFalse($round['resultsPublished']);
        self::assertNotNull($round['resultsFirstPublishedAt']);
    }

    public function testTableNumbersAreAssignedAsAWholeOrNotAtAll(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $url = '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/table-numbers';

        $this->post($url, ['assignments' => [['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'number' => 1]]]);

        self::assertResponseStatusCodeSame(422);
        $answer = $this->json();
        self::assertSame('invalid_table_numbers', $answer['error']);
        self::assertSame([[
            'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP,
            'reason' => 'table_number_taken',
            'message' => 'Another entrant of this round has this table number.',
        ]], $answer['problems']);

        $this->post($url, ['assignments' => [['entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'number' => 6]]]);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertSame(1, $answer['changed']);
        self::assertIsArray($answer['entries']);
        self::assertSame([6], array_column($answer['entries'], 'tableNumber'));
    }

    public function testTableNumbersCanBeSwitchedOffForARound(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post('/en/official-results/rounds/' . OfficialResultsFixture::ROUND_FINAL . '/table-numbers-usage', ['off' => true]);

        self::assertResponseIsSuccessful();
        $round = $this->json()['round'];
        self::assertIsArray($round);
        self::assertTrue($round['tableNumbersOff']);
        self::assertTrue($round['seated']);
    }

    public function testAdvancingShowsThePlanAndAppliesOnlyThatPlan(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $url = '/en/official-results/competitions/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/advance';
        $request = [
            'sourceRoundIds' => [OfficialResultsFixture::ROUND_PAIRS],
            'targetRoundIds' => [OfficialResultsFixture::ROUND_PAIRS_FINAL],
            'distribution' => 'single',
        ];

        $this->post($url, $request);
        self::assertResponseIsSuccessful();
        $plan = $this->json();
        self::assertFalse($plan['applied']);
        self::assertIsString($plan['planHash']);
        self::assertIsArray($plan['assignments']);
        self::assertCount(2, $plan['assignments']);

        $this->post($url, [...$request, 'dryRun' => false, 'planHash' => 'outdated']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('plan_changed', $this->json()['error']);

        $this->post($url, [...$request, 'dryRun' => false, 'planHash' => $plan['planHash']]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['applied']);

        $this->post($url, [...$request, 'targetRoundIds' => [OfficialResultsFixture::ROUND_FINAL]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('category_mismatch', $this->json()['reason']);
    }

    public function testAdvancingIntoAnotherEventsRoundIs404(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post('/en/official-results/competitions/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/advance', [
            'sourceRoundIds' => [OfficialResultsFixture::ROUND_GROUP_A],
            'targetRoundIds' => [CompetitionRoundFixture::ROUND_WJPC_FINAL],
            'distribution' => 'single',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdvancingInAnotherEventIsForbidden(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post('/en/official-results/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/advance', [
            'sourceRoundIds' => [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
            'targetRoundIds' => [CompetitionRoundFixture::ROUND_WJPC_FINAL],
            'distribution' => 'single',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $url, array $body, string $origin = 'http://localhost'): void
    {
        $this->browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function change(string $entry, string $field, mixed $from, mixed $to): array
    {
        return ['clientChangeId' => Uuid::uuid7()->toString(), 'entry' => $entry, 'field' => $field, 'from' => $from, 'to' => $to];
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }

    private function assertFilipNotQualified(): void
    {
        self::assertNull(self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT qualified_at FROM competition_participant_round WHERE id = :id',
            ['id' => OfficialResultsFixture::ENTRY_A_FILIP],
        ));
    }
}
