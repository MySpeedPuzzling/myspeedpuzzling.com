<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Referees;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A referee of an event (docs/features/competitions-management/live-results.md "Referees") enters results in the live
 * entry - and nothing else. The Results Cup is organised by PLAYER_WITH_STRIPE; PLAYER_WITH_FAVORITES referees it.
 */
final class RefereeRightsTest extends WebTestCase
{
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string REFEREE = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string OTHER = PlayerFixture::PLAYER_REGULAR;
    private const string ADMIN = PlayerFixture::PLAYER_ADMIN;

    private const string LIVE = '/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A;
    private const string STATE = '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A;
    private const string CHANGES = '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/changes';

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

    /**
     * @return iterable<string, array{null|string, int, int}>
     */
    public static function whoMayEnterResults(): iterable
    {
        // player => [live entry page status, round state status]
        yield 'organiser' => [self::ORGANISER, 200, 200];
        yield 'referee' => [self::REFEREE, 200, 200];
        yield 'admin' => [self::ADMIN, 200, 200];
        yield 'other player' => [self::OTHER, 403, 403];
        yield 'guest' => [null, 302, 401];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whoMayEnterResults')]
    public function testWhoMayOpenTheLiveEntryAndReadTheRound(null|string $playerId, int $pageStatus, int $stateStatus): void
    {
        if ($playerId !== null) {
            TestingLogin::asPlayer($this->browser, $playerId);
        }

        $this->browser->request('GET', self::LIVE);
        self::assertResponseStatusCodeSame($pageStatus);

        $this->browser->request('GET', self::STATE);
        self::assertResponseStatusCodeSame($stateStatus);
    }

    public function testTheRefereeGetsTheLiveEntryWithoutTheOrganiserTools(): void
    {
        TestingLogin::asPlayer($this->browser, self::REFEREE);

        $this->browser->request('GET', self::LIVE);

        self::assertResponseIsSuccessful();
        $html = (string) $this->browser->getResponse()->getContent();
        self::assertStringNotContainsString('/en/manage-event-rounds/', $html);
        self::assertStringNotContainsString('/en/manage-round-results/', $html);
        self::assertStringNotContainsString('/en/round-seating/', $html);
        self::assertSelectorNotExists('[data-live-results-target="addTable"]');
        self::assertSelectorNotExists('[data-live-results-target="readiness"]');
        self::assertSelectorExists('[data-live-results-target="findInput"]');
        self::assertSelectorExists('[data-live-results-target="addButton"]');
        // A referee cannot save table numbers - the quick add does not promise it
        self::assertSelectorTextSame('[data-live-results-add-note]', 'The entrant is added to this round with the first result you save.');
    }

    public function testTheOrganiserKeepsTheirTools(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('GET', self::LIVE);

        $html = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('/en/manage-event-rounds/', $html);
        self::assertStringContainsString('/en/manage-round-results/', $html);
        self::assertSelectorExists('[data-live-results-target="addTable"]');
        self::assertSelectorTextSame('[data-live-results-add-note]', 'The entrant is added to this round with the first result or table number you save.');
    }

    public function testTheRefereeRecordsAResult(): void
    {
        TestingLogin::asPlayer($this->browser, self::REFEREE);

        $this->post(self::CHANGES, ['changes' => [
            self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'result', null, ['seconds' => 4800]),
        ]]);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertIsArray($answer['outcomes']);
        self::assertSame(['applied'], array_column($answer['outcomes'], 'status'));

        $row = $this->database()->fetchAssociative(
            'SELECT result_seconds, result_entered_by_id FROM competition_participant_round WHERE id = :id',
            ['id' => OfficialResultsFixture::ENTRY_A_FILIP],
        );
        self::assertSame(['result_seconds' => 4800, 'result_entered_by_id' => self::REFEREE], $row);
    }

    public function testTheRefereeCanNotChangeTableNumbersOrQualificationNotEvenInOneSetWithAResult(): void
    {
        TestingLogin::asPlayer($this->browser, self::REFEREE);

        $this->post(self::CHANGES, ['changes' => [
            self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'table_number', null, 6),
            self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'qualified', false, true),
            self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'result', null, ['seconds' => 4800]),
        ]]);

        self::assertResponseIsSuccessful();
        $outcomes = $this->json()['outcomes'];
        self::assertIsArray($outcomes);
        self::assertSame(['rejected', 'rejected', 'applied'], array_column($outcomes, 'status'));
        self::assertSame(['results_only', 'results_only', null], array_column($outcomes, 'reason'));
        self::assertSame(['table_number', 'qualified', 'result'], array_column($outcomes, 'field'));
        $refused = $outcomes[0];
        self::assertIsArray($refused);
        self::assertSame('Referees enter results only - table numbers and qualification are up to the organisers.', $refused['message']);
        self::assertSame('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, $refused['entry']);

        $row = $this->database()->fetchAssociative(
            'SELECT table_number, qualified_at, result_seconds FROM competition_participant_round WHERE id = :id',
            ['id' => OfficialResultsFixture::ENTRY_A_FILIP],
        );
        self::assertSame(['table_number' => null, 'qualified_at' => null, 'result_seconds' => 4800], $row);
    }

    public function testTheRefereeQuickAddsAnEntrantWithAResultButWithoutATable(): void
    {
        TestingLogin::asPlayer($this->browser, self::REFEREE);
        $entryId = Uuid::uuid7()->toString();
        $newEntry = ['clientEntryId' => $entryId, 'kind' => 'person', 'name' => 'Walk In'];

        $this->post(self::CHANGES, ['changes' => [
            ['clientChangeId' => Uuid::uuid7()->toString(), 'newEntry' => $newEntry, 'field' => 'table_number', 'from' => null, 'to' => 9],
            ['clientChangeId' => Uuid::uuid7()->toString(), 'newEntry' => $newEntry, 'field' => 'result', 'from' => null, 'to' => ['seconds' => 5000]],
        ]]);

        self::assertResponseIsSuccessful();
        $outcomes = $this->json()['outcomes'];
        self::assertIsArray($outcomes);
        self::assertSame(['rejected', 'applied'], array_column($outcomes, 'status'));

        $row = $this->database()->fetchAssociative(
            'SELECT table_number, result_seconds FROM competition_participant_round WHERE id = :id',
            ['id' => $entryId],
        );
        self::assertSame(['table_number' => null, 'result_seconds' => 5000], $row);
    }

    public function testTheOrganiserStillChangesTableNumbersAndQualification(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->post(self::CHANGES, ['changes' => [
            self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'table_number', null, 6),
            self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'qualified', false, true),
        ]]);

        $outcomes = $this->json()['outcomes'];
        self::assertIsArray($outcomes);
        self::assertSame(['applied', 'applied'], array_column($outcomes, 'status'));
    }

    public function testARefereeOfThisEventIsNobodyAtAnotherEvent(): void
    {
        TestingLogin::asPlayer($this->browser, self::REFEREE);
        $this->database()->delete('competition_referee', ['player_id' => self::REFEREE]);

        $this->browser->request('GET', self::LIVE);
        self::assertResponseStatusCodeSame(403);

        $this->post(self::CHANGES, ['changes' => []]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function organiserOnlyPages(): iterable
    {
        $competition = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        $round = OfficialResultsFixture::ROUND_GROUP_A;

        yield 'results desk' => ['GET', '/en/manage-round-results/' . $round];
        yield 'results export' => ['GET', '/en/manage-round-results/' . $round . '/export/csv'];
        yield 'seating' => ['GET', '/en/round-seating/' . $round];
        yield 'seating print' => ['GET', '/en/print-round-seating/' . $round];
        yield 'results overview' => ['GET', '/en/manage-event-results/' . $competition];
        yield 'rounds' => ['GET', '/en/manage-event-rounds/' . $competition];
        yield 'stopwatch control' => ['GET', '/en/manage-round-stopwatch/' . $round];
        yield 'participants' => ['GET', '/en/manage-event-participants/' . $competition];
        yield 'registration' => ['GET', '/en/manage-event-registration/' . $competition];
        yield 'check-in' => ['GET', '/en/event-check-in/' . $competition];
        yield 'name tags' => ['GET', '/en/name-tags/' . $competition];
        yield 'event edit' => ['GET', '/en/edit-event/' . $competition];
        yield 'referees' => ['GET', '/en/manage-event-referees/' . $competition];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('organiserOnlyPages')]
    public function testTheRefereeCanNotOpenTheOtherOrganiserTools(string $method, string $url): void
    {
        TestingLogin::asPlayer($this->browser, self::REFEREE);

        $this->browser->request($method, $url);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function organiserOnlyEndpoints(): iterable
    {
        $round = '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A;

        yield 'publish' => [$round . '/publish', []];
        yield 'unpublish' => [$round . '/unpublish', []];
        yield 'table numbers' => [$round . '/table-numbers', ['assignments' => []]];
        yield 'table numbers usage' => [$round . '/table-numbers-usage', ['off' => true]];
        yield 'advance' => ['/en/official-results/competitions/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/advance', [
            'sourceRoundIds' => [OfficialResultsFixture::ROUND_PAIRS],
            'targetRoundIds' => [OfficialResultsFixture::ROUND_PAIRS_FINAL],
            'distribution' => 'single',
        ]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('organiserOnlyEndpoints')]
    public function testTheRefereeCanNotPublishSeatOrAdvance(string $url, array $body): void
    {
        TestingLogin::asPlayer($this->browser, self::REFEREE);

        $this->post($url, $body);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $this->json());
    }

    public function testTheRefereeCanNotReadTheSeatingProposal(): void
    {
        TestingLogin::asPlayer($this->browser, self::REFEREE);

        $this->browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/seating-proposal');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheEventLinkTakesTheRefereeToTheCurrentRound(): void
    {
        TestingLogin::asPlayer($this->browser, self::REFEREE);

        $this->browser->request('GET', '/en/live-results/event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        self::assertResponseRedirects('/en/live-results/' . OfficialResultsFixture::ROUND_PAIRS_FINAL . '?auto=1');
    }

    public function testANameTagScannedByTheRefereeOpensThePersonInTheLiveEntry(): void
    {
        TestingLogin::asPlayer($this->browser, self::REFEREE);

        $this->browser->request('GET', '/en/live/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/p/' . OfficialResultsFixture::PARTICIPANT_IVAN);

        self::assertResponseRedirects('/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_B . '?entrant=' . OfficialResultsFixture::PARTICIPANT_IVAN);
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

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
