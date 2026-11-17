<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OfficialResults;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The round state's conditional entries (judge-speed J2, docs/features/competitions-management/judge-offline.md C1):
 * `entriesKnown=<none|hash>` adds `entriesHash` (a hash of the entries as sent) and leaves `entries` out when the hash
 * is the one given - everything else of the answer stays (clock sample, token, viewer, round, proposals). Without the
 * parameter the answer is the one RoundResultsStateGoldenTest pins.
 */
final class RoundResultsStateConditionalTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string REFEREE = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string GROUP_B = OfficialResultsFixture::ROUND_GROUP_B;
    private const string STATE = '/en/official-results/rounds/' . self::GROUP_B . '?viewer=1';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testNoneAnswersTheEntriesWithTheirHashAndTheSameHashLeavesThemOut(): void
    {
        $this->database()->executeStatement('UPDATE competition SET time_proposals = true WHERE id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $full = $this->state('none');
        self::assertIsArray($full['entries'] ?? null);
        self::assertNotEmpty($full['entries']);
        self::assertArrayNotHasKey('entriesUnchanged', $full);
        $hash = $full['entriesHash'] ?? null;
        self::assertIsString($hash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hash);

        $unchanged = $this->state($hash);
        self::assertArrayNotHasKey('entries', $unchanged);
        self::assertTrue($unchanged['entriesUnchanged'] ?? null);
        self::assertSame($hash, $unchanged['entriesHash'] ?? null);

        // Everything but the entries stays in the answer
        foreach (['serverNow', 'serverNowMs', 'topic', 'competition', 'round', 'rounds', 'proposals', 'mercure', 'viewer'] as $key) {
            self::assertArrayHasKey($key, $unchanged, $key);
        }
        self::assertSame(['playerId' => self::ORGANISER, 'organiser' => true], $unchanged['viewer']);
        self::assertSame($full['round'], $unchanged['round']);

        // Another hash than the server's: the entries as always
        $other = $this->state(str_repeat('0', 32));
        self::assertSame($full['entries'], $other['entries'] ?? null);
        self::assertSame($hash, $other['entriesHash']);
    }

    public function testAResultATableNumberOrAParticipantsNameChangesTheHash(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $hashes = [$this->hash()];

        $this->database()->executeStatement('UPDATE competition_participant_round SET result_seconds = 3901 WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_B_GINA]);
        $hashes[] = $this->hash();

        $this->database()->executeStatement('UPDATE competition_participant_round SET table_number = 9 WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_B_HUGO]);
        $hashes[] = $this->hash();

        $this->database()->executeStatement('UPDATE competition_participant SET name = :name WHERE id = :id', ['name' => 'Ivan Renamed', 'id' => OfficialResultsFixture::PARTICIPANT_IVAN]);
        $hashes[] = $this->hash();

        self::assertCount(4, array_unique($hashes));

        // The answer to the old hash after a change is full again
        $answer = $this->state($hashes[0]);
        self::assertIsArray($answer['entries'] ?? null);
        self::assertSame($hashes[3], $answer['entriesHash']);
    }

    public function testARecordedResultThroughTheApiChangesTheHash(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $before = $this->hash();

        $this->browser->request('POST', '/en/official-results/rounds/' . self::GROUP_B . '/changes', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode(['changes' => [[
            'clientChangeId' => Uuid::uuid7()->toString(),
            'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_B_IVAN,
            'field' => 'result',
            'from' => ['seconds' => 5000],
            'to' => ['seconds' => 4999],
        ]]], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $answer = $this->state($before);
        self::assertIsArray($answer['entries'] ?? null);
        self::assertNotSame($before, $answer['entriesHash']);
    }

    public function testTheRefereesAndTheOrganisersViewsNeverShareAHash(): void
    {
        $this->database()->insert('competition_referee', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'player_id' => self::REFEREE,
            'added_by_id' => self::ORGANISER,
            'added_at' => '2026-10-01 10:00:00',
        ]);
        // Gina (private) does not let the referee see her: the referee's entries withhold her #code
        $this->database()->executeStatement(
            'DELETE FROM private_profile_viewer WHERE owner_id = :gina AND viewer_id = :referee',
            ['gina' => PlayerFixture::PLAYER_PRIVATE, 'referee' => self::REFEREE],
        );

        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $organisers = $this->hash();
        TestingLogin::asPlayer($this->browser, self::REFEREE);
        $referees = $this->hash();
        self::assertNotSame($organisers, $referees);

        // The organiser's hash never makes the referee's answer leave out their (different) entries
        $answer = $this->state($organisers);
        self::assertIsArray($answer['entries'] ?? null);
        self::assertSame($referees, $answer['entriesHash']);
        self::assertStringNotContainsStringIgnoringCase('player2', (string) $this->browser->getResponse()->getContent());
    }

    public function testAnythingElseIsTheParameterAbsentAndEntriesZeroWins(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $hash = $this->hash();

        foreach (['', 'NONE', strtoupper($hash), $hash . '0', substr($hash, 1), 'x' . substr($hash, 1)] as $garbage) {
            $answer = $this->get(self::STATE . '&entriesKnown=' . urlencode($garbage));
            self::assertIsArray($answer['entries'] ?? null, $garbage);
            self::assertArrayNotHasKey('entriesHash', $answer, $garbage);
            self::assertArrayNotHasKey('entriesUnchanged', $answer, $garbage);
        }

        $answer = $this->get(self::STATE . '&entries=0&entriesKnown=' . $hash);
        self::assertArrayNotHasKey('entries', $answer);
        self::assertArrayNotHasKey('entriesHash', $answer);
        self::assertArrayNotHasKey('entriesUnchanged', $answer);
        self::assertArrayHasKey('serverNowMs', $answer);
    }

    public function testTheParameterCostsNoStatement(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $hash = $this->hash();

        $this->startCountingQueries($this->browser);
        $this->get(self::STATE);
        $without = $this->queryCount($this->browser);

        $this->startCountingQueries($this->browser);
        $this->get(self::STATE . '&entriesKnown=none');
        $none = $this->queryCount($this->browser);

        $this->startCountingQueries($this->browser);
        $this->get(self::STATE . '&entriesKnown=' . $hash);
        $unchanged = $this->queryCount($this->browser);

        self::assertSame($without, $none);
        self::assertSame($without, $unchanged);
    }

    private function hash(): string
    {
        $hash = $this->state('none')['entriesHash'] ?? null;
        self::assertIsString($hash);

        return $hash;
    }

    /**
     * @return array<mixed>
     */
    private function state(string $known): array
    {
        return $this->get(self::STATE . '&entriesKnown=' . $known);
    }

    /**
     * @return array<mixed>
     */
    private function get(string $url): array
    {
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));

        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
