<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Message\PublishCompetitionStanding;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The public JSON of a published standing (competition toolkit v2 S1, displays-standings.md §3.1, `public_standing_state`):
 * the guest snapshot through an explicit whitelist (no row keys, no player / participant / entry ids), 404 for unknown,
 * unpublished and not public, an ETag answered with 304, a fixed statement budget and a fresh answer right after a
 * signalled write (the generation bump).
 *
 * World Series (WorldSeriesSetup): solo rounds 1-3 published - Dora 2400 s, Bob 2700, Alice 3000, Cyril 3300.
 */
final class PublicStandingStateTest extends WebTestCase
{
    use QueryCountAssertions;
    use WorldSeriesSetup;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        $this->createWorldSeries(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testTheShapeIsAWhitelistOfThePublishedRounds(): void
    {
        $standingId = $this->publishedSolo(new StandingRules(2));

        $json = $this->json($standingId);
        $raw = (string) $this->browser->getResponse()->getContent();

        self::assertSame(['standing', 'rounds', 'rows', 'generatedAtMs'], array_keys($json));
        self::assertSame([
            'id' => $standingId,
            'name' => 'World Series - Solo',
            'category' => 'solo',
            'mode' => 'sum',
            'published' => true,
            'provisional' => true,
            'roundsCounted' => 3,
            'roundsTotal' => 6,
            'rules' => ['penaltySecondsPerPiece' => 2, 'order' => 'finished_rounds_first', 'missing' => 'incomplete', 'penaliseFinishedMissing' => false],
            'url' => 'http://localhost/en/events/world-series-test/standings/' . $this->slug($standingId),
        ], $json['standing']);

        self::assertIsArray($json['rounds']);
        self::assertCount(6, $json['rounds']);
        self::assertSame(['id' => strtolower($this->soloRounds[0]), 'name' => 'Solo 1', 'slug' => 'solo-1', 'minutesLimit' => 60, 'counted' => true, 'url' => 'http://localhost/en/events/world-series-test/results/solo-1'], $json['rounds'][0]);
        // An unpublished round: listed, not counted, no link
        self::assertSame(['id' => strtolower($this->soloRounds[3]), 'name' => 'Solo 4', 'slug' => 'solo-4', 'minutesLimit' => 60, 'counted' => false, 'url' => null], $json['rounds'][3]);

        self::assertIsArray($json['rows']);
        self::assertSame([[1, 'Dora', 2400], [2, 'Bob', 2700], [3, 'Alice', 3000], [4, 'Cyril', 3300]], array_map(
            static fn (mixed $row): array => [self::at($row, 'rank'), self::at($row, 'name'), self::at($row, 'totalSeconds')],
            $json['rows'],
        ));

        $dora = $json['rows'][0];
        self::assertIsArray($dora);
        self::assertSame(['rank', 'complete', 'name', 'countries', 'entrants', 'totalSeconds', 'totalTimeSeconds', 'totalMissingPieces', 'totalPenaltySeconds', 'roundsScored', 'roundsFinished', 'perRound'], array_keys($dora));
        self::assertSame([['name' => 'Dora', 'country' => 'cz', 'playerCode' => null]], $dora['entrants']);
        self::assertSame(['cz'], $dora['countries']);
        self::assertSame(3, $dora['roundsScored']);
        self::assertIsArray($dora['perRound']);
        self::assertCount(6, $dora['perRound']);
        self::assertSame([
            'roundId' => strtolower($this->soloRounds[0]),
            'status' => 'finished',
            'scoreSeconds' => 800,
            'timeSeconds' => 800,
            'missingPieces' => 0,
            'penaltySeconds' => 0,
            'rank' => 1,
            'result' => ['seconds' => 800, 'piecesPlaced' => null],
        ], $dora['perRound'][0]);
        self::assertSame('not_counted', self::at($dora, 'perRound.5.status'));
        self::assertIsArray($dora['perRound'][5]);
        self::assertArrayHasKey('result', $dora['perRound'][5]);
        self::assertNull($dora['perRound'][5]['result']);

        // Nothing that identifies a row inside the system: no keys, no participant / line-up / entry ids
        self::assertStringNotContainsString('"key"', $raw);
        self::assertStringNotContainsString('playerId', $raw);
        self::assertStringNotContainsString(self::WS_COMPETITION, $raw);
        self::assertDoesNotMatchRegularExpression('/018d00a6-0000-0000-0000-0000000002\d\d/', $raw);
        self::assertDoesNotMatchRegularExpression('/"[plt]:/', $raw);

        $response = $this->browser->getResponse();
        self::assertSame('max-age=0, must-revalidate, public', $response->headers->get('Cache-Control'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        self::assertNotEmpty($response->getEtag());
        self::assertSame([], $response->headers->getCookies());
    }

    public function testTheLocaleOnlyChangesTheUrls(): void
    {
        $standingId = $this->publishedSolo();

        $english = $this->json($standingId);
        $czech = $this->json($standingId, '?locale=cs');
        self::assertSame('http://localhost/eventy/world-series-test/poradi/' . $this->slug($standingId), self::at($czech, 'standing.url'));
        self::assertSame($english['rows'], $czech['rows']);
        // An unknown locale is English
        self::assertSame($english['standing'], $this->json($standingId, '?locale=xx')['standing']);
    }

    public function testAnUnchangedStandingAnswers304(): void
    {
        $standingId = $this->publishedSolo();
        $this->json($standingId);
        $etag = (string) $this->browser->getResponse()->getEtag();

        $this->browser->request('GET', '/api/competition-standings/' . $standingId, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
        self::assertSame('', (string) $this->browser->getResponse()->getContent());

        // Another round published = another body, another ETag
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => $this->soloRounds[3]]);
        $this->browser->request('GET', '/api/competition-standings/' . $standingId, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseIsSuccessful();
        self::assertNotSame($etag, $this->browser->getResponse()->getEtag());
    }

    public function testNotFoundForUnknownUnpublishedAndNotPublic(): void
    {
        $standingId = $this->create();

        $this->browser->request('GET', '/api/competition-standings/' . Uuid::uuid7()->toString());
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], json_decode((string) $this->browser->getResponse()->getContent(), true));
        self::assertSame('*', $this->browser->getResponse()->headers->get('Access-Control-Allow-Origin'));

        $this->browser->request('GET', '/api/competition-standings/not-a-uuid');
        self::assertResponseStatusCodeSame(404);

        // Not published - for its organiser too
        $this->browser->request('GET', '/api/competition-standings/' . $standingId);
        self::assertResponseStatusCodeSame(404);
        TestingLogin::asPlayer($this->browser, self::WS_ORGANISER);
        $this->browser->request('GET', '/api/competition-standings/' . $standingId);
        self::assertResponseStatusCodeSame(404);

        $this->publish($standingId);
        $this->browser->request('GET', '/api/competition-standings/' . $standingId);
        self::assertResponseIsSuccessful();

        $this->database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => self::WS_COMPETITION]);
        $this->browser->request('GET', '/api/competition-standings/' . $standingId);
        self::assertResponseStatusCodeSame(404, 'A draft\'s standing is public');

        $this->database->executeStatement('UPDATE competition SET is_draft = false, approved_at = NULL WHERE id = :id', ['id' => self::WS_COMPETITION]);
        $this->browser->request('GET', '/api/competition-standings/' . $standingId);
        self::assertResponseStatusCodeSame(404, 'An unapproved event\'s standing is public');
    }

    /**
     * Statements (displays-standings.md §3.1): warm 1 (the event's visibility - the snapshot is cached), cold ≤ 5 (the
     * standing's event, visibility, the standing with its event's slugs, the rounds, the entries of its one category).
     */
    public function testTheStatementBudget(): void
    {
        $standingId = $this->publishedSolo();
        $this->keepTheCacheAcrossRequests();

        $this->startCountingQueries($this->browser);
        $this->json($standingId);
        $this->assertQueryCountAtMost($this->browser, 5, 'cold');

        $this->startCountingQueries($this->browser);
        $this->json($standingId);
        self::assertSame(1, $this->queryCount($this->browser), 'warm');
    }

    /**
     * No stale window after a signalled write: the snapshot is cached, an unsignalled change is not seen (until the
     * snapshot's 60 s end), the after-commit hook (CompetitionDisplayLiveUpdates - every round / results write goes
     * through it) starts a new generation and the next answer is fresh.
     */
    public function testASignalledWriteIsSeenAtOnce(): void
    {
        $standingId = $this->publishedSolo();
        $this->keepTheCacheAcrossRequests();
        self::assertSame(3, self::at($this->json($standingId), 'standing.roundsCounted'));

        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => $this->soloRounds[3]]);
        self::assertSame(3, self::at($this->json($standingId), 'standing.roundsCounted'), 'No canary: the snapshot is not cached');

        $this->browser->getContainer()->get(CompetitionDisplayLiveUpdates::class)->roundChanged($this->soloRounds[3]);
        $json = $this->json($standingId);
        self::assertSame(4, self::at($json, 'standing.roundsCounted'));
        // Dora did not start round 4 (the public view shows ranked results only: "not in the round") - now incomplete,
        // listed after the complete ones without a rank
        self::assertSame([[1, 'Bob'], [2, 'Alice'], [3, 'Cyril'], [null, 'Dora']], array_map(
            static fn (mixed $row): array => [self::at($row, 'rank'), self::at($row, 'name')],
            (array) $json['rows'],
        ));
        self::assertSame('not_in_round', self::at($json, 'rows.3.perRound.3.status'));
    }

    /**
     * The test pool is an array adapter, emptied by the kernel's reset after every request (production: Redis, kept) -
     * one kernel for the test and a pool of its own that a reset does not empty, as on production.
     */
    private function keepTheCacheAcrossRequests(): void
    {
        $this->browser->disableReboot();
        self::getContainer()->set('competition_standings_cache', new FilesystemAdapter('s1_' . bin2hex(random_bytes(6)), 0, sys_get_temp_dir() . '/public-standing-state-test'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $standingId, string $query = ''): array
    {
        $this->browser->request('GET', '/api/competition-standings/' . $standingId . $query);
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        /** @var array<string, mixed> $json */
        return $json;
    }

    private static function at(mixed $value, string $path): mixed
    {
        foreach (explode('.', $path) as $part) {
            self::assertIsArray($value, $path);
            self::assertArrayHasKey($part, $value, $path);
            $value = $value[$part];
        }

        return $value;
    }

    private function publishedSolo(StandingRules $rules = new StandingRules()): string
    {
        $standingId = $this->create($rules);
        $this->publish($standingId);

        return $standingId;
    }

    private function create(StandingRules $rules = new StandingRules()): string
    {
        $id = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new CreateCompetitionStanding($id, self::WS_COMPETITION, 'World Series - Solo', RoundCategory::Solo, $this->soloRounds, $rules));

        return $id->toString();
    }

    private function publish(string $standingId): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new PublishCompetitionStanding($standingId, self::WS_COMPETITION));
    }

    private function slug(string $standingId): string
    {
        $slug = $this->database->fetchOne('SELECT slug FROM competition_standing WHERE id = :id', ['id' => $standingId]);
        assert(is_string($slug));

        return $slug;
    }
}
